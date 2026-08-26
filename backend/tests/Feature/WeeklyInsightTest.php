<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use App\Models\WeeklyInsight;
use App\Services\AI\Contracts\NutritionInsightGenerator;
use App\Services\AI\Data\WeeklyNutritionSummary;
use App\Services\AI\Exceptions\AiUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WeeklyInsightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 25 August 2026 — the week runs Mon 24th to Sun 30th.
        Carbon::setTestNow('2026-08-25 12:00:00');
        config()->set('ai.provider', 'fake');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A user with a goal and enough logged days for the current week. */
    private function userWithAWeek(): User
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->withCalorieTarget(2000)->create();

        Meal::factory()->for($user)->on('2026-08-24')->withTotals(1900, 140, 200, 60)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(2100, 150, 210, 70)->create();
        Meal::factory()->for($user)->on('2026-08-26')->withTotals(2000, 130, 190, 65)->create();

        return $user;
    }

    /** Swap in a generator that returns a fixed payload. */
    private function fakeGenerator(array $payload): void
    {
        $this->app->bind(NutritionInsightGenerator::class, fn () => new class($payload) implements NutritionInsightGenerator
        {
            public function __construct(private array $payload)
            {
            }

            public function generate(WeeklyNutritionSummary $summary): array
            {
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
        });
    }

    public function test_insight_endpoints_require_authentication(): void
    {
        $this->getJson('/api/insights')->assertUnauthorized();
        $this->getJson('/api/insights/current')->assertUnauthorized();
        $this->postJson('/api/insights/generate')->assertUnauthorized();
    }

    public function test_a_new_user_has_no_insights_and_not_enough_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/insights')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($user)->getJson('/api/insights/current')
            ->assertOk()
            ->assertJsonPath('data.week_start', '2026-08-24')
            ->assertJsonPath('data.week_end', '2026-08-30')
            ->assertJsonPath('data.is_current_week', true)
            ->assertJsonPath('data.insight', null)
            ->assertJsonPath('data.has_enough_data', false)
            ->assertJsonPath('data.requirement.days_logged', 0)
            ->assertJsonPath('data.requirement.min_days_logged', 3);
    }

    public function test_a_thin_week_is_reported_as_insufficient_data_without_calling_the_ai(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-24')->withTotals(1800)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(1900)->create();

        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertOk()
            ->assertJsonPath('status', 'insufficient_data')
            ->assertJsonPath('data.requirement.days_logged', 2)
            ->assertJsonPath('data.aggregates.days_logged', 2);

        // Nothing stored, because nothing was generated.
        $this->assertDatabaseCount('weekly_insights', 0);
    }

    public function test_a_summary_is_generated_and_stored_from_the_real_aggregates(): void
    {
        $user = $this->userWithAWeek();

        $response = $this->actingAs($user)->postJson('/api/insights/generate')->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('reused', false)
            ->assertJsonPath('data.insight.week_start', '2026-08-24')
            ->assertJsonPath('data.insight.week_end', '2026-08-30')
            ->assertJsonPath('data.insight.stats.days_logged', 3)
            ->assertJsonPath('data.insight.stats.meals_logged', 3)
            ->assertJsonPath('data.insight.stats.avg_calories', 2000)
            ->assertJsonPath('data.insight.stats.avg_protein', 140)
            ->assertJsonPath('data.insight.stats.calorie_target', 2000)
            ->assertJsonPath('data.insight.stats.days_close_to_target', 3)
            ->assertJsonPath('data.insight.ai_provider', 'fake');

        $this->assertNotEmpty($response->json('data.insight.summary'));
        $this->assertNotEmpty($response->json('data.insight.headline'));
        $this->assertNotEmpty($response->json('data.insight.observations'));

        // Asserted through Eloquent rather than assertDatabaseHas: `week_start`
        // is a date column, and its raw stored form differs between MySQL and
        // the SQLite the suite runs on.
        $stored = WeeklyInsight::query()
            ->where('user_id', $user->id)
            ->whereDate('week_start', '2026-08-24')
            ->sole();

        $this->assertSame(3, $stored->days_logged);
        $this->assertSame(2000, $stored->avg_calories);
        $this->assertSame('fake', $stored->ai_provider);
        $this->assertNotNull($stored->data_hash);
    }

    public function test_a_second_request_reuses_the_stored_summary(): void
    {
        $user = $this->userWithAWeek();

        $first = $this->actingAs($user)->postJson('/api/insights/generate')->assertOk();
        $second = $this->actingAs($user)->postJson('/api/insights/generate')->assertOk();

        $second->assertJsonPath('reused', true)
            ->assertJsonPath('data.insight.id', $first->json('data.insight.id'))
            ->assertJsonPath(
                'data.insight.generated_at',
                $first->json('data.insight.generated_at'),
            );

        // One row, one generation — no duplicate week and no second AI call.
        $this->assertDatabaseCount('weekly_insights', 1);
    }

    public function test_changing_the_data_makes_the_stored_summary_stale(): void
    {
        $user = $this->userWithAWeek();

        $this->actingAs($user)->postJson('/api/insights/generate')->assertOk();

        $this->actingAs($user)->getJson('/api/insights/current')
            ->assertOk()
            ->assertJsonPath('data.is_stale', false);

        Meal::factory()->for($user)->on('2026-08-27')->withTotals(2400, 160, 240, 80)->create();

        $this->actingAs($user)->getJson('/api/insights/current')
            ->assertOk()
            ->assertJsonPath('data.is_stale', true);

        // A changed fingerprint means the next request regenerates rather than
        // describing meals that no longer match.
        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertOk()
            ->assertJsonPath('reused', false)
            ->assertJsonPath('data.insight.stats.days_logged', 4);

        $this->assertDatabaseCount('weekly_insights', 1);
    }

    public function test_force_regenerates_even_when_nothing_changed(): void
    {
        $user = $this->userWithAWeek();

        $this->actingAs($user)->postJson('/api/insights/generate')->assertOk();

        $this->actingAs($user)->postJson('/api/insights/generate', ['force' => true])
            ->assertOk()
            ->assertJsonPath('reused', false);

        $this->assertDatabaseCount('weekly_insights', 1);
    }

    public function test_the_previous_week_is_compared_when_it_has_enough_days(): void
    {
        $user = $this->userWithAWeek();

        // Previous week: Mon 17 to Sun 23 August.
        Meal::factory()->for($user)->on('2026-08-17')->withTotals(1700, 120, 180, 55)->create();
        Meal::factory()->for($user)->on('2026-08-18')->withTotals(1800, 125, 185, 58)->create();
        Meal::factory()->for($user)->on('2026-08-19')->withTotals(1900, 130, 190, 60)->create();

        $response = $this->actingAs($user)->postJson('/api/insights/generate')->assertOk();

        $response->assertJsonPath('data.insight.comparison.week_start', '2026-08-17')
            ->assertJsonPath('data.insight.comparison.days_logged', 3)
            ->assertJsonPath('data.insight.comparison.averages.calories', 1800)
            ->assertJsonPath('data.insight.comparison.averages.protein', 125);

        $this->assertStringContainsString(
            '125',
            $response->json('data.insight.summary'),
            'A comparison week should be quoted in the summary.',
        );
    }

    public function test_a_thin_previous_week_is_not_compared_against(): void
    {
        $user = $this->userWithAWeek();

        Meal::factory()->for($user)->on('2026-08-18')->withTotals(1800)->create();

        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertOk()
            ->assertJsonPath('data.insight.comparison', null)
            ->assertJsonPath('data.aggregates.previous_week', null);
    }

    public function test_an_untraceable_number_is_rejected_rather_than_stored(): void
    {
        $user = $this->userWithAWeek();

        // 421 g of protein is nowhere in this user's data.
        $this->fakeGenerator([
            'headline' => 'Strong protein week',
            'summary' => 'You averaged 421g of protein this week, which is a big jump on where you were.',
            'observations' => ['Protein was the standout figure this week.'],
            'suggestions' => [],
        ]);

        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertStatus(502)
            ->assertJsonPath('retryable', true);

        $this->assertDatabaseCount('weekly_insights', 0);
    }

    public function test_a_traceable_number_is_accepted(): void
    {
        $user = $this->userWithAWeek();

        $this->fakeGenerator([
            'headline' => 'A consistent week',
            'summary' => 'You averaged 2,000 kcal and 140g of protein across the 3 days you logged.',
            'observations' => ['All 3 logged days landed within 10% of your 2,000 kcal target.'],
            'suggestions' => ['Logging the rest of the week would make the average more reliable.'],
        ]);

        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertOk()
            ->assertJsonPath('data.insight.ai_provider', 'stub')
            ->assertJsonPath('data.insight.ai_model', 'stub-model')
            ->assertJsonPath('data.insight.headline', 'A consistent week');

        $this->assertDatabaseCount('weekly_insights', 1);
    }

    public function test_medical_framing_is_rejected(): void
    {
        $user = $this->userWithAWeek();

        $this->fakeGenerator([
            'headline' => 'Possible protein deficiency',
            'summary' => 'You averaged 2,000 kcal this week, which may point to a deficiency.',
            'observations' => ['You should see a doctor about this.'],
            'suggestions' => [],
        ]);

        $this->actingAs($user)->postJson('/api/insights/generate')->assertStatus(502);

        $this->assertDatabaseCount('weekly_insights', 0);
    }

    public function test_a_malformed_response_is_rejected(): void
    {
        $user = $this->userWithAWeek();

        $this->fakeGenerator(['headline' => 'Missing everything else']);

        $this->actingAs($user)->postJson('/api/insights/generate')->assertStatus(502);

        $this->assertDatabaseCount('weekly_insights', 0);
    }

    public function test_an_unavailable_provider_gives_503_and_stores_nothing(): void
    {
        $user = $this->userWithAWeek();

        $this->app->bind(NutritionInsightGenerator::class, fn () => new class implements NutritionInsightGenerator
        {
            public function generate(WeeklyNutritionSummary $summary): array
            {
                throw new AiUnavailableException('Down.');
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function modelName(): string
            {
                return 'stub-model';
            }
        });

        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertStatus(503)
            ->assertJsonPath('retryable', true);

        $this->assertDatabaseCount('weekly_insights', 0);
    }

    public function test_a_missing_api_key_gives_503(): void
    {
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.providers.anthropic.api_key', '');

        $user = $this->userWithAWeek();

        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertStatus(503)
            ->assertJsonPath('retryable', false);
    }

    public function test_a_past_week_can_be_summarised(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->withCalorieTarget(2000)->create();

        Meal::factory()->for($user)->on('2026-08-17')->withTotals(1900, 130, 200, 60)->create();
        Meal::factory()->for($user)->on('2026-08-18')->withTotals(2000, 140, 210, 65)->create();
        Meal::factory()->for($user)->on('2026-08-19')->withTotals(2100, 150, 220, 70)->create();

        $this->actingAs($user)->postJson('/api/insights/generate', ['date' => '2026-08-19'])
            ->assertOk()
            ->assertJsonPath('data.insight.week_start', '2026-08-17')
            ->assertJsonPath('data.insight.week_end', '2026-08-23');

        $this->actingAs($user)->getJson('/api/insights/current?date=2026-08-19')
            ->assertOk()
            ->assertJsonPath('data.is_current_week', false)
            ->assertJsonPath('data.week_start', '2026-08-17');
    }

    public function test_the_week_window_follows_the_users_timezone(): void
    {
        // 20:00 UTC Sunday is already Monday morning in Auckland, so that user
        // is in the *next* week.
        Carbon::setTestNow('2026-08-23 20:00:00');

        $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);

        $this->actingAs($user)->getJson('/api/insights/current')
            ->assertOk()
            ->assertJsonPath('data.week_start', '2026-08-24');

        $utcUser = User::factory()->create(['timezone' => 'UTC']);

        $this->actingAs($utcUser)->getJson('/api/insights/current')
            ->assertOk()
            ->assertJsonPath('data.week_start', '2026-08-17');
    }

    public function test_the_index_is_scoped_to_the_caller(): void
    {
        $user = User::factory()->create();
        $other = $this->userWithAWeek();

        $this->actingAs($other)->postJson('/api/insights/generate')->assertOk();

        $this->actingAs($user)->getJson('/api/insights')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_user_cannot_read_another_users_insight(): void
    {
        $owner = $this->userWithAWeek();
        $intruder = User::factory()->create();

        $id = $this->actingAs($owner)->postJson('/api/insights/generate')
            ->assertOk()
            ->json('data.insight.id');

        $this->actingAs($intruder)->getJson("/api/insights/{$id}")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/insights/{$id}")->assertOk();
    }

    public function test_generating_never_reads_another_users_meals(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->withCalorieTarget(2000)->create();

        Meal::factory()->for($user)->on('2026-08-24')->withTotals(1000, 50, 100, 30)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(1000, 50, 100, 30)->create();
        Meal::factory()->for($user)->on('2026-08-26')->withTotals(1000, 50, 100, 30)->create();

        $other = User::factory()->create();
        Meal::factory()->for($other)->on('2026-08-24')->withTotals(9000, 500, 900, 400)->create();

        $this->actingAs($user)->postJson('/api/insights/generate')
            ->assertOk()
            ->assertJsonPath('data.insight.stats.avg_calories', 1000)
            ->assertJsonPath('data.insight.stats.meals_logged', 3);
    }

    public function test_the_history_list_is_newest_week_first(): void
    {
        $user = User::factory()->create();

        WeeklyInsight::factory()->for($user)->create(['week_start' => '2026-08-10', 'week_end' => '2026-08-16']);
        WeeklyInsight::factory()->for($user)->create(['week_start' => '2026-08-17', 'week_end' => '2026-08-23']);

        $this->actingAs($user)->getJson('/api/insights')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.week_start', '2026-08-17')
            ->assertJsonPath('data.1.week_start', '2026-08-10');
    }
}
