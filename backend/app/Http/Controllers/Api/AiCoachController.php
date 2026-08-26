<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coach\SendCoachMessageRequest;
use App\Http\Requests\Coach\StoreConversationRequest;
use App\Http\Resources\AiChatMessageResource;
use App\Http\Resources\AiConversationResource;
use App\Models\AiConversation;
use App\Services\AI\CoachService;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The NutriLens AI Coach.
 *
 * Every action is reached either through `$request->user()->aiConversations()`
 * or through a policy check on a bound conversation, so there is no parameter
 * that can widen a response to another account. Messages are never addressed
 * directly: the only route to one is through a conversation the caller owns.
 */
class AiCoachController extends Controller
{
    public function __construct(private readonly CoachService $coach)
    {
    }

    /**
     * GET /api/ai-coach/context
     *
     * The caller's live nutrition context: today's targets, totals, remaining
     * macros, meals logged, streak and a seven-day summary.
     *
     * This is deliberately the *same* object the model is given, so the
     * "Today's progress" card on the coach screen cannot show one set of
     * numbers while the coach answers from another. No AI call happens here.
     */
    public function context(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->coach->context($request->user())->toSummary(),
                /*
                 * Whether replies will come from the offline driver. The
                 * provider *name* is not exposed — only whether the UI should
                 * label answers as simulated, which it has to know to be
                 * honest with the user.
                 */
                'is_simulated' => config('ai.provider') === 'fake',
            ],
        ]);
    }

    /**
     * GET /api/ai-coach/conversations
     *
     * The caller's threads, most recent activity first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $paginated = $request->user()->aiConversations()
            ->newestFirst()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => AiConversationResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * POST /api/ai-coach/conversations
     *
     * Starts an empty thread. No AI call and no cost: a conversation is just a
     * container until something is said in it.
     */
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = $this->coach->startConversation(
            $request->user(),
            $request->validated()['title'] ?? null,
        );

        return response()->json([
            'message' => 'New chat started.',
            'data' => AiConversationResource::make($conversation),
        ], 201);
    }

    /**
     * GET /api/ai-coach/conversations/{conversation}
     */
    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        // Ownership, not just authentication.
        $this->authorize('view', $conversation);

        return response()->json([
            'data' => AiConversationResource::make($conversation->load('messages')),
        ]);
    }

    /**
     * DELETE /api/ai-coach/conversations/{conversation}
     *
     * Messages go with it — the foreign key cascades.
     */
    public function destroy(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(['message' => 'Chat deleted.']);
    }

    /**
     * POST /api/ai-coach/conversations/{conversation}/messages
     *
     * Answers one question inside a thread. Rebuilds the nutrition context
     * from scratch, replays a bounded window of the conversation, calls the
     * provider once, and stores the question and the answer together.
     */
    public function sendMessage(
        SendCoachMessageRequest $request,
        AiConversation $conversation,
    ): JsonResponse {
        $this->authorize('update', $conversation);

        $user = $request->user();

        try {
            $result = $this->coach->send(
                $user,
                $conversation,
                $request->validated()['message'],
            );
        } catch (AiException $e) {
            Log::warning('AI Coach reply failed', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'provider' => config('ai.provider'),
                'exception' => $e::class,
            ]);

            return response()->json([
                'message' => $this->messageFor($e),
                'retryable' => $e->retryable(),
            ], $e->status());
        }

        return response()->json([
            'data' => [
                'user_message' => AiChatMessageResource::make($result['user_message']),
                'reply' => AiChatMessageResource::make($result['reply']),
                'conversation' => AiConversationResource::make($conversation->fresh()),
                // The context the answer was written from, so the progress card
                // on the screen stays in step without a second request.
                'context' => $result['context']->toSummary(),
            ],
        ], 201);
    }

    /**
     * The shared AiException messages are written for photo analysis, so they
     * are re-phrased here for a conversation rather than shown verbatim. None
     * of them names a provider, a model, a key or a URL.
     */
    private function messageFor(AiException $exception): string
    {
        return match (true) {
            $exception instanceof AiConfigurationException =>
                'The AI Coach is not configured on this server yet. Your nutrition figures are unaffected — '
                    .'you can still track meals and goals as usual.',
            $exception instanceof AiUnavailableException =>
                'The AI service is temporarily unavailable. Please try again in a moment.',
            $exception instanceof AiResponseException =>
                'That answer did not come back in a usable state, so it was discarded. Please try again.',
            default => 'Your coach could not answer that. Please try again.',
        };
    }
}
