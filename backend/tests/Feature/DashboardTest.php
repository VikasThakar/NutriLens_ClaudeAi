<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use App\Models\WeeklyInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Phase 3 additions to GET /api/dashboard/today: the streak, the seven-day
 * trend, recent meals and the latest weekly summary. The day-totals behaviour
 * this endpoint already had is covered by MealTest and NutritionGoalTest.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_brand_new_account_is_distinguishable_from_a_quiet_day(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/dashboard/today')
            ->assertOk()
            ->assertJsonPath('data.has_any_meals', false)
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.streak.current', 0)
            ->assertJsonPath('data.streak.longest', 0)
            ->assertJsonPath('data.latest_insight', null)
            ->assertJsonCount(0, 'data.recent_meals')
            ->assertJsonCount(7, 'data.trend');
    }

    public function test_a_quiet_day_on_an_established_account_still_has_history(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-22')->withTotals(1900)->create();

        $response = $this->actingAs($user)->getJson('/api/dashboard/today')->assertOk();

        // Nothing today, but the account is not new — the dashboard needs to
        // tell those two states apart to pick the right empty state.
        $response->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.has_any_meals', true)
            ->assertJsonCount(1, 'data.recent_meals');
    }

    public function test_the_trend_covers_the_last_seven_days_including_today(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-19')->withTotals(1500)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(2100)->create();

        $trend = $this->actingAs($user)->getJson('/api/dashboard/today')
            ->assertOk()
            ->json('data.trend');

        $this->assertCount(7, $trend);
        $this->assertSame('2026-08-19', $trend[0]['date']);
        $this->assertSame('2026-08-25', $trend[6]['date']);
        $this->assertSame(1500, $trend[0]['calories']);
        $this->assertSame(2100, $trend[6]['calories']);
        $this->assertFalse($trend[3]['logged']);
    }

    public function test_the_streak_is_reported_alongside_the_day(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-23')->create();
        Meal::factory()->for($user)->on('2026-08-24')->create();
        Meal::factory()->for($user)->on('2026-08-25')->create();

        $this->actingAs($user)->getJson('/api/dashboard/today')
            ->assertOk()
            ->assertJsonPath('data.streak.current', 3)
            ->assertJsonPath('data.streak.logged_today', true)
            ->assertJsonCount(14, 'data.streak.recent');
    }

    public function test_recent_meals_are_newest_first_and_capped(): void
    {
        $user = User::factory()->create();

        foreach (['2026-08-20', '2026-08-21', '2026-08-22', '2026-08-23', '2026-08-24'] as $date) {
            Meal::factory()->for($user)->on($date)->create();
        }

        $recent = $this->actingAs($user)->getJson('/api/dashboard/today')
            ->assertOk()
            ->json('data.recent_meals');

        $this->assertCount(4, $recent);
        $this->assertSame('2026-08-24', $recent[0]['consumed_on']);
        $this->assertSame('2026-08-21', $recent[3]['consumed_on']);
    }

    public function test_the_latest_weekly_insight_is_included(): void
    {
        $user = User::factory()->create();

        WeeklyInsight::factory()->for($user)->create([
            'week_start' => '2026-08-10',
            'week_end' => '2026-08-16',
            'headline' => 'An older week',
        ]);
        WeeklyInsight::factory()->for($user)->create([
            'week_start' => '2026-08-17',
            'week_end' => '2026-08-23',
            'headline' => 'The most recent week',
        ]);

        $this->actingAs($user)->getJson('/api/dashboard/today')
            ->assertOk()
            ->assertJsonPath('data.latest_insight.headline', 'The most recent week')
            ->assertJsonPath('data.latest_insight.week_start', '2026-08-17');
    }

    public function test_the_dashboard_extras_never_leak_between_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        NutritionGoal::factory()->for($other)->create();
        Meal::factory()->for($other)->on('2026-08-25')->withTotals(2500)->create();
        WeeklyInsight::factory()->for($other)->create();

        $this->actingAs($user)->getJson('/api/dashboard/today')
            ->assertOk()
            ->assertJsonPath('data.has_any_meals', false)
            ->assertJsonPath('data.streak.current', 0)
            ->assertJsonPath('data.latest_insight', null)
            ->assertJsonCount(0, 'data.recent_meals');
    }
}
