<?php

namespace App\Services\AI;

use App\Enums\ChatRole;
use App\Models\AiChatMessage;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\AI\Contracts\NutritionCoach;
use App\Services\AI\Data\CoachContext;
use App\Services\AI\Exceptions\AiResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The AI Coach, end to end.
 *
 * The order of operations is the point of this class:
 *
 *  1. Build a **fresh** nutrition context from the user's real meals. Never
 *     reuse the context a previous turn was answered with — a conversation
 *     opened this morning must not be answered from this morning's macros.
 *  2. Replay only a bounded window of the conversation, each turn truncated,
 *     so token cost stays flat however long a thread gets.
 *  3. Call the provider once.
 *  4. Validate what comes back: shape, length, and that it did not stray into
 *     clinical territory.
 *  5. Persist the user turn and the reply together, or neither.
 *
 * Step 5 is what makes the retry button safe: a failed request leaves no
 * orphaned user message behind, so sending again cannot duplicate it.
 */
class CoachService
{
    /**
     * Prior turns replayed to the model. Ten is five exchanges — enough for a
     * conversation to hold its thread, short enough that the context (which is
     * regenerated in full every turn) stays the dominant input.
     */
    public const HISTORY_MESSAGES = 10;

    /** Longest question accepted from the client. */
    public const MAX_MESSAGE_LENGTH = 1000;

    /** Each replayed turn is truncated to this, so history cannot grow unbounded. */
    private const MAX_HISTORY_CHARS = 1200;

    /** Longest reply stored. Anything longer is a runaway response, not an answer. */
    private const MAX_REPLY_LENGTH = 6000;

    /** Follow-up chips kept. Extras are dropped, not treated as a failure. */
    private const MAX_SUGGESTIONS = 3;

    /** Longest follow-up chip kept, before it stops fitting on a phone. */
    private const MAX_SUGGESTION_LENGTH = 80;

    /**
     * Wording that would turn nutrition guidance into a clinical claim.
     *
     * Deliberately narrower than the weekly-insight list: the coach is
     * *encouraged* to point people at a doctor or dietitian, so those words
     * are not blocked. What is blocked is the model asserting a diagnosis.
     */
    private const FORBIDDEN_FRAGMENTS = [
        'you have been diagnosed',
        'you are diagnosed',
        'i diagnose',
        'you likely have',
        'you probably have a',
        'you are deficient in',
        'you have a deficiency',
        'i prescribe',
        'this will cure',
        'this will treat your',
    ];

    public function __construct(
        private readonly NutritionCoach $coach,
        private readonly CoachContextService $contexts,
    ) {
    }

    /** The user's live nutrition context — what the coach knows right now. */
    public function context(User $user): CoachContext
    {
        return $this->contexts->forUser($user);
    }

    /**
     * Start a thread. No AI call: a conversation is just a container until
     * something is said in it.
     */
    public function startConversation(User $user, ?string $title = null): AiConversation
    {
        return $user->aiConversations()->create([
            'title' => $title !== null ? $this->titleFrom($title) : null,
            // Set explicitly rather than relying on the column default, so the
            // model returned to the client already carries it.
            'message_count' => 0,
        ]);
    }

    /**
     * Answer one message inside a conversation the caller owns.
     *
     * @return array{context:CoachContext, user_message:AiChatMessage, reply:AiChatMessage}
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function send(User $user, AiConversation $conversation, string $message): array
    {
        $message = trim($message);
        $context = $this->contexts->forUser($user);
        $history = $this->history($conversation);

        $payload = $this->coach->reply($context, $history, $message);
        $reply = $this->validate($payload);

        [$userMessage, $assistantMessage] = DB::transaction(
            fn () => $this->store($conversation, $message, $reply)
        );

        return [
            'context' => $context,
            'user_message' => $userMessage,
            'reply' => $assistantMessage,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Conversation context                                                */
    /* ------------------------------------------------------------------ */

    /**
     * The tail of the conversation, oldest first.
     *
     * Three things happen here, and each exists for a reason:
     *
     *  - Only the most recent turns are taken, so a long thread costs the same
     *    as a short one.
     *  - Each turn is truncated, so one enormous stored message cannot blow
     *    the budget on its own.
     *  - A window that opens on an assistant turn has that turn dropped:
     *    providers expect a conversation to begin with the user.
     *
     * @return list<array{role:string, content:string}>
     */
    private function history(AiConversation $conversation): array
    {
        // reorder() first: the relation is defined ascending, and appending a
        // descending clause would leave the ascending one in front of it — so
        // the limit would take the *oldest* turns rather than the newest.
        $messages = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit(self::HISTORY_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        while ($messages->isNotEmpty() && $messages->first()->role !== ChatRole::User) {
            $messages->shift();
        }

        return $messages
            ->map(fn (AiChatMessage $message) => [
                'role' => $message->role->value,
                'content' => Str::limit($message->content, self::MAX_HISTORY_CHARS),
            ])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message:string, suggestions:list<string>}
     *
     * @throws AiResponseException
     */
    private function validate(array $payload): array
    {
        /*
         * Only the answer itself is strict. A missing, over-long or malformed
         * `suggestions` list is repaired rather than treated as a failure:
         * throwing away a good answer because the follow-up chips came back
         * wrong would be the wrong call for the user.
         */
        $validator = Validator::make($payload, [
            'message' => ['required', 'string', 'min:1', 'max:'.self::MAX_REPLY_LENGTH],
        ]);

        if ($validator->fails()) {
            Log::warning('AI Coach reply failed schema validation', [
                'provider' => $this->coach->providerName(),
                'errors' => $validator->errors()->toArray(),
            ]);

            throw new AiResponseException('The AI response did not match the expected schema.');
        }

        $message = $this->cleanBody((string) $payload['message']);

        if ($message === '') {
            throw new AiResponseException('The AI returned an empty answer.');
        }

        $this->rejectClinicalClaims($message);

        return [
            'message' => $message,
            'suggestions' => $this->cleanSuggestions($payload['suggestions'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function cleanSuggestions(mixed $suggestions): array
    {
        if (! is_array($suggestions)) {
            return [];
        }

        $clean = [];

        foreach ($suggestions as $suggestion) {
            if (! is_string($suggestion)) {
                continue;
            }

            $line = Str::limit($this->cleanLine($suggestion), self::MAX_SUGGESTION_LENGTH);

            if ($line !== '') {
                $clean[] = $line;
            }

            if (count($clean) === self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $clean;
    }

    /** @throws AiResponseException */
    private function rejectClinicalClaims(string $text): void
    {
        $lower = Str::lower($text);

        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                Log::warning('AI Coach reply rejected for a clinical claim', [
                    'provider' => $this->coach->providerName(),
                    'fragment' => $fragment,
                ]);

                throw new AiResponseException(
                    'That answer strayed into medical territory, so it was discarded. Try rephrasing your question.'
                );
            }
        }
    }

    /**
     * Replies render as plain text with paragraph breaks, so markdown emphasis
     * and headings are stripped rather than shown as literal asterisks. Bullet
     * lines are left alone — a short list sometimes genuinely reads better.
     */
    private function cleanBody(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/^#{1,6}\s*/m', '', $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
        $text = (string) preg_replace('/(?<!\S)[*_](\S(?:.*?\S)?)[*_](?!\S)/s', '$1', $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);

        return trim($text);
    }

    private function cleanLine(string $text): string
    {
        $text = (string) preg_replace('/[*_`#]+/', '', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /* ------------------------------------------------------------------ */
    /* Persistence                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array{message:string, suggestions:list<string>}  $reply
     * @return array{0:AiChatMessage, 1:AiChatMessage}
     */
    private function store(AiConversation $conversation, string $message, array $reply): array
    {
        $userMessage = $conversation->messages()->create([
            'role' => ChatRole::User,
            'content' => $message,
        ]);

        $assistantMessage = $conversation->messages()->create([
            'role' => ChatRole::Assistant,
            'content' => $reply['message'],
            'suggestions' => $reply['suggestions'] === [] ? null : $reply['suggestions'],
            'ai_provider' => $this->coach->providerName(),
            'ai_model' => $this->coach->modelName(),
        ]);

        // A thread is named after the question that started it, rather than
        // spending an extra AI call on a title nobody asked for.
        $conversation->fill([
            'title' => $conversation->title ?: $this->titleFrom($message),
            'last_message_at' => $assistantMessage->created_at,
            'message_count' => $conversation->message_count + 2,
        ])->save();

        return [$userMessage, $assistantMessage];
    }

    private function titleFrom(string $message): string
    {
        $title = $this->cleanLine($message);

        return $title === '' ? 'New chat' : Str::limit($title, 60);
    }
}
