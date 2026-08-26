<?php

namespace Tests\Feature;

use App\Enums\ChatRole;
use App\Models\AiChatMessage;
use App\Models\AiConversation;
use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use App\Services\AI\Contracts\NutritionCoach;
use App\Services\AI\CoachService;
use App\Services\AI\Data\CoachContext;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Throwable;

class AiCoachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 25 August 2026, midday.
        Carbon::setTestNow('2026-08-25 12:00:00');
        config()->set('ai.provider', 'fake');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A user with the worked example from the brief: a 2,000 kcal / 140 g
     * protein target, 1,420 kcal and 72 g logged today.
     */
    private function userWithToday(): User
    {
        $user = User::factory()->create();

        NutritionGoal::factory()->for($user)->create([
            'calorie_target' => 2000,
            'protein_target' => 140,
            'carb_target' => 220,
            'fat_target' => 65,
        ]);

        Meal::factory()->for($user)->on('2026-08-25', 8)
            ->withTotals(700, 40, 90, 25)->create(['meal_name' => 'Porridge and Berries']);
        Meal::factory()->for($user)->on('2026-08-25', 13)
            ->withTotals(720, 32, 90, 20)->create(['meal_name' => 'Chicken Wrap']);

        return $user;
    }

    /** A user with several earlier days as well as today. */
    private function userWithHistory(): User
    {
        $user = $this->userWithToday();

        Meal::factory()->for($user)->on('2026-08-24')->withTotals(1950, 120, 210, 62)->create();
        Meal::factory()->for($user)->on('2026-08-23')->withTotals(2400, 95, 260, 90)
            ->create(['meal_name' => 'Birthday Dinner']);
        Meal::factory()->for($user)->on('2026-08-22')->withTotals(1800, 130, 190, 55)->create();

        return $user;
    }

    /**
     * Swap in a coach that records what it was asked and returns a fixed
     * payload. Returned so the test can inspect the call afterwards.
     */
    private function stubCoach(
        ?array $payload = null,
        ?Throwable $throws = null,
    ): object {
        $stub = new class($payload ?? ['message' => 'A stub answer.', 'suggestions' => []], $throws) implements NutritionCoach
        {
            /** @var list<array{context:CoachContext, history:array, message:string}> */
            public array $calls = [];

            public function __construct(private array $payload, private ?Throwable $throws)
            {
            }

            public function reply(CoachContext $context, array $history, string $message): array
            {
                $this->calls[] = compact('context', 'history', 'message');

                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->payload;
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function modelName(): string
            {
                return 'stub-model';
            }
        };

        $this->app->instance(NutritionCoach::class, $stub);

        return $stub;
    }

    private function conversationFor(User $user): AiConversation
    {
        return AiConversation::factory()->for($user)->create(['title' => null]);
    }

    /* ------------------------------------------------------------------ */
    /* Authentication                                                      */
    /* ------------------------------------------------------------------ */

    public function test_every_coach_endpoint_requires_authentication(): void
    {
        $conversation = $this->conversationFor(User::factory()->create());

        $this->getJson('/api/ai-coach/context')->assertUnauthorized();
        $this->getJson('/api/ai-coach/conversations')->assertUnauthorized();
        $this->postJson('/api/ai-coach/conversations')->assertUnauthorized();
        $this->getJson("/api/ai-coach/conversations/{$conversation->id}")->assertUnauthorized();
        $this->deleteJson("/api/ai-coach/conversations/{$conversation->id}")->assertUnauthorized();
        $this->postJson("/api/ai-coach/conversations/{$conversation->id}/messages", [
            'message' => 'Hello',
        ])->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ */
    /* Context                                                             */
    /* ------------------------------------------------------------------ */

    public function test_the_context_endpoint_reports_todays_real_progress(): void
    {
        $user = $this->userWithToday();

        $this->actingAs($user)->getJson('/api/ai-coach/context')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-08-25')
            ->assertJsonPath('data.has_goal', true)
            ->assertJsonPath('data.targets.calories', 2000)
            ->assertJsonPath('data.targets.protein', 140)
            ->assertJsonPath('data.consumed.calories', 1420)
            ->assertJsonPath('data.consumed.protein', 72)
            ->assertJsonPath('data.remaining.calories', 580)
            ->assertJsonPath('data.remaining.protein', 68)
            ->assertJsonPath('data.percent_of_target.calories', 71)
            ->assertJsonPath('data.meals_logged_today', 2)
            ->assertJsonPath('data.is_simulated', true);
    }

    public function test_the_context_endpoint_works_for_a_brand_new_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/ai-coach/context')
            ->assertOk()
            ->assertJsonPath('data.has_goal', false)
            ->assertJsonPath('data.has_any_meals', false)
            ->assertJsonPath('data.targets', null)
            ->assertJsonPath('data.remaining', null)
            ->assertJsonPath('data.consumed.calories', 0)
            ->assertJsonPath('data.meals_logged_today', 0);
    }

    public function test_a_user_with_goals_but_no_meals_gets_full_targets_back(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->withCalorieTarget(2400)->create();

        $this->actingAs($user)->getJson('/api/ai-coach/context')
            ->assertOk()
            ->assertJsonPath('data.has_goal', true)
            ->assertJsonPath('data.consumed.calories', 0)
            ->assertJsonPath('data.remaining.calories', 2400)
            ->assertJsonPath('data.meals_logged_today', 0);
    }

    public function test_the_context_never_leaks_identity_or_credentials(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
        ]);
        NutritionGoal::factory()->for($user)->create();
        Meal::factory()->for($user)->on('2026-08-25')->create();

        $stub = $this->stubCoach();
        $conversation = $this->conversationFor($user);

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What should I eat next?'],
        )->assertCreated();

        $payload = json_encode($stub->calls[0]['context']->toPayload());

        $this->assertStringNotContainsString('ada@example.test', $payload);
        $this->assertStringNotContainsString('Ada Lovelace', $payload);
        $this->assertStringNotContainsString('Lovelace', $payload);
        $this->assertStringNotContainsString($user->password, $payload);
        $this->assertStringNotContainsString('"user_id"', $payload);
        $this->assertStringNotContainsString('"id"', $payload);
        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('token', $payload);
    }

    public function test_the_context_given_to_the_model_carries_the_precomputed_figures(): void
    {
        $user = $this->userWithHistory();
        $stub = $this->stubCoach();
        $conversation = $this->conversationFor($user);

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'How am I doing?'],
        )->assertCreated();

        $payload = $stub->calls[0]['context']->toPayload();

        $this->assertSame(580, $payload['today']['remaining']['calories']);
        $this->assertSame(68.0, (float) $payload['today']['remaining']['protein']);
        $this->assertSame('protein', $payload['derived']['macro_furthest_below_target']);
        $this->assertFalse($payload['derived']['calories_over_target']);
        $this->assertSame(2, $payload['today']['meals_logged']);
        // The largest meal in the window is resolved by query, not by the model.
        $this->assertSame('Birthday Dinner', $payload['largest_meal_in_last_7_days']['name']);
        $this->assertSame(4, $payload['last_7_days_summary']['days_logged']);
        $this->assertCount(7, $payload['last_7_days']);
    }

    /* ------------------------------------------------------------------ */
    /* Conversations                                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_conversation_can_be_started_without_calling_the_ai(): void
    {
        $user = $this->userWithToday();
        $stub = $this->stubCoach();

        $this->actingAs($user)->postJson('/api/ai-coach/conversations')
            ->assertCreated()
            ->assertJsonPath('data.title', null)
            ->assertJsonPath('data.message_count', 0);

        $this->assertSame([], $stub->calls, 'Starting a chat must not cost an AI call.');
        $this->assertDatabaseCount('ai_conversations', 1);
    }

    public function test_sending_a_message_stores_both_turns_and_returns_the_reply(): void
    {
        $user = $this->userWithToday();
        $this->stubCoach([
            'message' => "You have about 580 kcal left today.\n\nProtein is 68g short.",
            'suggestions' => ['Suggest a dinner', 'How did my week go?'],
        ]);

        $conversation = $this->conversationFor($user);

        $response = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What should I eat for dinner?'],
        );

        $response->assertCreated()
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.user_message.content', 'What should I eat for dinner?')
            ->assertJsonPath('data.reply.role', 'assistant')
            ->assertJsonPath('data.reply.suggestions', ['Suggest a dinner', 'How did my week go?'])
            ->assertJsonPath('data.reply.ai_provider', 'stub')
            ->assertJsonPath('data.reply.is_simulated', false)
            ->assertJsonPath('data.conversation.message_count', 2)
            // The context the answer was written from travels back with it.
            ->assertJsonPath('data.context.remaining.calories', 580);

        $this->assertStringContainsString('580 kcal left', $response->json('data.reply.content'));

        $this->assertDatabaseCount('ai_chat_messages', 2);
        $this->assertSame(
            'What should I eat for dinner?',
            $conversation->fresh()->title,
            'The thread should be named after the question that started it.',
        );
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_a_conversation_can_be_continued_and_replays_its_history(): void
    {
        $user = $this->userWithToday();
        $stub = $this->stubCoach();
        $conversation = $this->conversationFor($user);

        AiChatMessage::factory()->for($conversation, 'conversation')
            ->fromUser('How much protein do I still need?')->create();
        AiChatMessage::factory()->for($conversation, 'conversation')
            ->fromAssistant('You need 68g more protein.')->create();

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'And what about carbs?'],
        )->assertCreated();

        $history = $stub->calls[0]['history'];

        $this->assertCount(2, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('How much protein do I still need?', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertSame('And what about carbs?', $stub->calls[0]['message']);
    }

    public function test_replayed_history_is_capped(): void
    {
        $user = $this->userWithToday();
        $stub = $this->stubCoach();
        $conversation = $this->conversationFor($user);

        // 30 turns: far more than the window.
        for ($i = 0; $i < 15; $i++) {
            AiChatMessage::factory()->for($conversation, 'conversation')
                ->fromUser("Question {$i}")->create();
            AiChatMessage::factory()->for($conversation, 'conversation')
                ->fromAssistant("Answer {$i}")->create();
        }

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'One more thing'],
        )->assertCreated();

        $history = $stub->calls[0]['history'];

        $this->assertLessThanOrEqual(CoachService::HISTORY_MESSAGES, count($history));
        // The window must open on a user turn, whatever it happened to land on.
        $this->assertSame('user', $history[0]['role']);
        // And it must be the *end* of the conversation, not the beginning.
        $this->assertSame('Answer 14', end($history)['content']);
    }

    public function test_the_conversation_list_is_scoped_to_the_caller_and_newest_first(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $older = AiConversation::factory()->for($user)->create([
            'title' => 'Older chat',
            'last_message_at' => Carbon::parse('2026-08-20 10:00:00'),
        ]);
        $newer = AiConversation::factory()->for($user)->create([
            'title' => 'Newer chat',
            'last_message_at' => Carbon::parse('2026-08-24 10:00:00'),
        ]);
        AiConversation::factory()->for($other)->create(['title' => 'Not yours']);

        $response = $this->actingAs($user)->getJson('/api/ai-coach/conversations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
        $response->assertJsonMissing(['title' => 'Not yours']);
    }

    public function test_a_conversation_loads_with_its_messages_in_order(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationFor($user);

        AiChatMessage::factory()->for($conversation, 'conversation')->fromUser('First')->create();
        AiChatMessage::factory()->for($conversation, 'conversation')->fromAssistant('Second')->create();

        $this->actingAs($user)->getJson("/api/ai-coach/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.content', 'First')
            ->assertJsonPath('data.messages.1.content', 'Second')
            ->assertJsonPath('data.messages.1.is_simulated', true);
    }

    public function test_deleting_a_conversation_removes_its_messages(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationFor($user);
        AiChatMessage::factory()->for($conversation, 'conversation')->fromUser()->create();

        $this->actingAs($user)->deleteJson("/api/ai-coach/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Chat deleted.');

        $this->assertDatabaseCount('ai_conversations', 0);
        $this->assertDatabaseCount('ai_chat_messages', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Ownership                                                           */
    /* ------------------------------------------------------------------ */

    public function test_a_user_cannot_read_another_users_conversation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = $this->conversationFor($owner);

        AiChatMessage::factory()->for($conversation, 'conversation')
            ->fromUser('Private question')->create();

        $this->actingAs($intruder)
            ->getJson("/api/ai-coach/conversations/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_a_user_cannot_send_into_another_users_conversation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = $this->conversationFor($owner);
        $stub = $this->stubCoach();

        $this->actingAs($intruder)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'Whose data is this?'],
        )->assertForbidden();

        $this->assertSame([], $stub->calls, 'A forbidden request must not reach the provider.');
        $this->assertDatabaseCount('ai_chat_messages', 0);
    }

    public function test_a_user_cannot_delete_another_users_conversation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = $this->conversationFor($owner);

        $this->actingAs($intruder)
            ->deleteJson("/api/ai-coach/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('ai_conversations', 1);
    }

    public function test_one_users_meals_never_reach_another_users_coach(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->create();

        $other = User::factory()->create();
        Meal::factory()->for($other)->on('2026-08-25')
            ->withTotals(999, 11, 22, 33)->create(['meal_name' => 'Somebody Elses Lunch']);

        $stub = $this->stubCoach();
        $conversation = $this->conversationFor($user);

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What did I eat?'],
        )->assertCreated();

        $payload = json_encode($stub->calls[0]['context']->toPayload());

        $this->assertStringNotContainsString('Somebody Elses Lunch', $payload);
        $this->assertSame(0, $stub->calls[0]['context']->consumed['calories']);
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    public function test_an_empty_message_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationFor($user);
        $stub = $this->stubCoach();

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => '   '],
        )->assertStatus(422)->assertJsonValidationErrors('message');

        $this->assertSame([], $stub->calls);
    }

    public function test_an_over_long_message_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationFor($user);
        $this->stubCoach();

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => str_repeat('a', CoachService::MAX_MESSAGE_LENGTH + 1)],
        )->assertStatus(422)->assertJsonValidationErrors('message');
    }

    /* ------------------------------------------------------------------ */
    /* Failure handling                                                    */
    /* ------------------------------------------------------------------ */

    public function test_a_missing_api_key_gives_503_and_stores_nothing(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);

        $this->stubCoach(throws: new AiConfigurationException('AI_API_KEY is not set.'));

        $response = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'Are you there?'],
        );

        $response->assertStatus(503)->assertJsonPath('retryable', false);

        // The message must not leak anything about the server's configuration.
        $this->assertStringNotContainsString('AI_API_KEY', $response->json('message'));

        $this->assertDatabaseCount('ai_chat_messages', 0);
    }

    public function test_an_unavailable_provider_leaves_no_orphaned_user_message(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);

        $this->stubCoach(throws: new AiUnavailableException('Upstream is down.'));

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What should I eat next?'],
        )->assertStatus(503)->assertJsonPath('retryable', true);

        // This is what makes the retry button safe: nothing was half-written,
        // so sending again cannot duplicate the question.
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertSame(0, $conversation->fresh()->message_count);
    }

    public function test_an_empty_ai_answer_is_rejected(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);

        $this->stubCoach(['message' => '', 'suggestions' => []]);

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'Hello?'],
        )->assertStatus(502);

        $this->assertDatabaseCount('ai_chat_messages', 0);
    }

    public function test_a_clinical_claim_is_discarded(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);

        $this->stubCoach([
            'message' => 'Based on your protein intake you have a deficiency and should take supplements.',
            'suggestions' => [],
        ]);

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'Am I short of anything?'],
        )->assertStatus(502);

        $this->assertDatabaseCount('ai_chat_messages', 0);
    }

    public function test_pointing_the_user_at_a_professional_is_allowed(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);

        $this->stubCoach([
            'message' => 'That is outside what I can help with — a doctor or registered dietitian is the right person to ask.',
            'suggestions' => [],
        ]);

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'I have a medical question'],
        )->assertCreated();
    }

    public function test_markdown_is_stripped_and_stray_suggestions_are_repaired(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);

        $this->stubCoach([
            'message' => "## Dinner\n\n**Grilled chicken** with rice.\n\n\n\nThat fits your day.",
            'suggestions' => ['One', 'Two', 'Three', 'Four', 42, ''],
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'Dinner ideas?'],
        )->assertCreated();

        $content = $response->json('data.reply.content');

        $this->assertStringNotContainsString('##', $content);
        $this->assertStringNotContainsString('**', $content);
        $this->assertStringContainsString('Grilled chicken with rice.', $content);
        $this->assertStringNotContainsString("\n\n\n", $content);

        // A malformed suggestions list is repaired, not treated as a failure.
        $this->assertSame(['One', 'Two', 'Three'], $response->json('data.reply.suggestions'));
    }

    /* ------------------------------------------------------------------ */
    /* Rate limiting                                                       */
    /* ------------------------------------------------------------------ */

    public function test_coach_messages_are_rate_limited_per_user(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);
        $this->stubCoach();

        RateLimiter::clear('user:'.$user->id);

        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($user)->postJson(
                "/api/ai-coach/conversations/{$conversation->id}/messages",
                ['message' => "Question {$i}"],
            )->assertCreated();
        }

        $limited = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'One too many'],
        );

        $limited->assertStatus(429);
        $this->assertNotNull(
            $limited->headers->get('Retry-After'),
            'A 429 must tell the caller when to retry.',
        );

        // A different user is unaffected: the bucket is the account, not the IP.
        $other = $this->userWithToday();
        $otherConversation = $this->conversationFor($other);

        $this->actingAs($other)->postJson(
            "/api/ai-coach/conversations/{$otherConversation->id}/messages",
            ['message' => 'Am I limited too?'],
        )->assertCreated();
    }

    /* ------------------------------------------------------------------ */
    /* The offline (fake) driver                                           */
    /* ------------------------------------------------------------------ */

    public function test_the_fake_coach_answers_from_the_users_real_numbers(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);

        $content = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What should I eat for dinner?'],
        )->assertCreated()->json('data.reply.content');

        // The real remaining figures, not canned prose.
        $this->assertStringContainsString('580', $content);
        $this->assertStringContainsString('68', $content);
        $this->assertStringContainsString('rotein', $content);
        // And it says what it is.
        $this->assertStringContainsString('without an AI key', $content);
    }

    public function test_the_fake_coach_reads_the_question_it_was_asked(): void
    {
        $user = $this->userWithHistory();
        $conversation = $this->conversationFor($user);

        $week = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'Analyze my week'],
        )->assertCreated()->json('data.reply.content');

        $this->assertStringContainsString('last seven days', $week);
        $this->assertStringContainsString('4 days', $week);

        $biggest = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What was my highest-calorie meal this week?'],
        )->assertCreated()->json('data.reply.content');

        $this->assertStringContainsString('Birthday Dinner', $biggest);
        $this->assertStringContainsString('2,400', $biggest);
    }

    public function test_the_fake_coach_still_helps_a_user_with_no_data(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversationFor($user);

        $content = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What should I eat next?'],
        )->assertCreated()->json('data.reply.content');

        $this->assertStringContainsString('not logged anything today', $content);
        $this->assertStringContainsString('targets', $content);
        // It must not invent a target to measure against.
        $this->assertStringNotContainsString('2,000 kcal target', $content);
    }

    public function test_the_fake_coach_reports_being_over_target_honestly(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->create([
            'calorie_target' => 1800,
            'protein_target' => 140,
            'carb_target' => 160,
            'fat_target' => 60,
        ]);
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(2100, 150, 200, 70)->create();

        $conversation = $this->conversationFor($user);

        $content = $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'What should I eat next?'],
        )->assertCreated()->json('data.reply.content');

        $this->assertStringContainsString('300', $content);
        $this->assertStringContainsString('past your', $content);
    }

    /* ------------------------------------------------------------------ */
    /* Persistence across a reload                                         */
    /* ------------------------------------------------------------------ */

    public function test_history_survives_a_reload_because_it_lives_in_mysql(): void
    {
        $user = $this->userWithToday();
        $conversation = $this->conversationFor($user);
        $this->stubCoach(['message' => 'Stored answer.', 'suggestions' => ['Follow up?']]);

        $this->actingAs($user)->postJson(
            "/api/ai-coach/conversations/{$conversation->id}/messages",
            ['message' => 'Remember this'],
        )->assertCreated();

        // A fresh request, as a page reload would make.
        $this->actingAs($user)->getJson("/api/ai-coach/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.content', 'Remember this')
            ->assertJsonPath('data.messages.1.content', 'Stored answer.')
            ->assertJsonPath('data.messages.1.suggestions', ['Follow up?']);

        $this->assertDatabaseHas('ai_chat_messages', [
            'conversation_id' => $conversation->id,
            'role' => ChatRole::Assistant->value,
            'content' => 'Stored answer.',
        ]);
    }
}
