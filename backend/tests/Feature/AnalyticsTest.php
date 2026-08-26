<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsTest extends TestCase
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

    private function userWithTarget(int $calories = 2000): User
    {
        $user = User::factory()->create();

        NutritionGoal::factory()->for($user)->withCalorieTarget($calories)->create();

        return $user;
    }

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson('/api/analytics')->assertUnauthorized();
    }

    public function test_a_new_user_gets_a_complete_but_empty_week(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/analytics?range=week')->assertOk();

        // Seven rows, all zero — an empty range is still a shaped range, so the
        // client never has to guess at the axis.
        $response->assertJsonPath('data.range.days', 7)
            ->assertJsonPath('data.range.granularity', 'day')
            ->assertJsonPath('data.summary.days_logged', 0)
            ->assertJsonPath('data.summary.total_meals', 0)
            ->assertJsonPath('data.summary.averages.calories', 0)
            ->assertJsonPath('data.summary.target_adherence.percent', null)
            ->assertJsonPath('data.targets', null)
            ->assertJsonCount(7, 'data.series');

        $this->assertFalse($response->json('data.series.0.logged'));
    }

    public function test_a_single_day_of_meals_is_reported_exactly(): void
    {
        $user = $this->userWithTarget();

        Meal::factory()->for($user)->on('2026-08-25')->withTotals(600, 40, 60, 20)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(400, 30, 30, 10)->create();

        $response = $this->actingAs($user)->getJson('/api/analytics?range=week')->assertOk();

        $response->assertJsonPath('data.summary.days_logged', 1)
            ->assertJsonPath('data.summary.total_meals', 2)
            ->assertJsonPath('data.summary.averages.calories', 1000)
            ->assertJsonPath('data.summary.averages.protein', 70)
            ->assertJsonPath('data.summary.totals.calories', 1000)
            ->assertJsonPath('data.targets.calories', 2000);

        $lastDay = collect($response->json('data.series'))->last();

        $this->assertSame('2026-08-25', $lastDay['date']);
        $this->assertSame(1000, $lastDay['calories']);
        $this->assertSame(2, $lastDay['meals']);
        $this->assertTrue($lastDay['logged']);
    }

    public function test_averages_are_per_logged_day_not_per_calendar_day(): void
    {
        $user = $this->userWithTarget();

        // Two logged days inside a seven-day range: the average must be 1,500,
        // not 3,000 / 7.
        Meal::factory()->for($user)->on('2026-08-24')->withTotals(1000, 50, 100, 30)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(2000, 150, 200, 70)->create();

        $this->actingAs($user)->getJson('/api/analytics?range=week')
            ->assertOk()
            ->assertJsonPath('data.summary.days_logged', 2)
            ->assertJsonPath('data.summary.averages.calories', 1500)
            ->assertJsonPath('data.summary.averages.protein', 100);
    }

    public function test_missing_days_are_present_in_the_series_rather_than_skipped(): void
    {
        $user = $this->userWithTarget();

        Meal::factory()->for($user)->on('2026-08-20')->withTotals(1800)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(1900)->create();

        $series = collect(
            $this->actingAs($user)->getJson('/api/analytics?range=week')->assertOk()->json('data.series')
        );

        $this->assertCount(7, $series);
        $this->assertSame(
            ['2026-08-19', '2026-08-20', '2026-08-21', '2026-08-22', '2026-08-23', '2026-08-24', '2026-08-25'],
            $series->pluck('date')->all(),
        );
        $this->assertSame(5, $series->where('logged', false)->count());
    }

    public function test_days_close_to_target_uses_a_ten_percent_band(): void
    {
        $user = $this->userWithTarget(2000);

        // 1,850 and 2,150 are inside ±10% of 2,000; 1,600 is not.
        Meal::factory()->for($user)->on('2026-08-23')->withTotals(1850)->create();
        Meal::factory()->for($user)->on('2026-08-24')->withTotals(2150)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(1600)->create();

        $this->actingAs($user)->getJson('/api/analytics?range=week')
            ->assertOk()
            ->assertJsonPath('data.summary.target_adherence.days_close_to_target', 2)
            ->assertJsonPath('data.summary.target_adherence.days_logged', 3)
            ->assertJsonPath('data.summary.target_adherence.tolerance_percent', 10)
            ->assertJsonPath('data.summary.target_adherence.calorie_target', 2000)
            ->assertJsonPath('data.summary.target_adherence.percent', 67);
    }

    public function test_the_band_edges_are_inclusive(): void
    {
        $user = $this->userWithTarget(2000);

        Meal::factory()->for($user)->on('2026-08-24')->withTotals(1800)->create();
        Meal::factory()->for($user)->on('2026-08-25')->withTotals(2200)->create();

        $this->actingAs($user)->getJson('/api/analytics?range=week')
            ->assertOk()
            ->assertJsonPath('data.summary.target_adherence.days_close_to_target', 2);
    }

    public function test_adherence_reports_null_rather_than_zero_without_a_target(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-25')->withTotals(1900)->create();

        $this->actingAs($user)->getJson('/api/analytics?range=week')
            ->assertOk()
            ->assertJsonPath('data.summary.target_adherence.percent', null)
            ->assertJsonPath('data.summary.target_adherence.calorie_target', null)
            ->assertJsonPath('data.summary.days_logged', 1);
    }

    public function test_a_long_range_is_bucketed_by_week(): void
    {
        $user = $this->userWithTarget();

        Meal::factory()->for($user)->on('2026-08-25')->withTotals(2000)->create();
        Meal::factory()->for($user)->on('2026-07-01')->withTotals(1000)->create();

        $response = $this->actingAs($user)->getJson('/api/analytics?range=quarter')->assertOk();

        $response->assertJsonPath('data.range.days', 90)
            ->assertJsonPath('data.range.granularity', 'week');

        $series = collect($response->json('data.series'));

        // 90 days spans 14 Monday-anchored buckets, and every bucket date is a
        // Monday.
        $this->assertGreaterThan(10, $series->count());
        $this->assertLessThan(16, $series->count());

        foreach ($series as $bucket) {
            $this->assertSame(
                Carbon::MONDAY,
                Carbon::parse($bucket['date'])->dayOfWeek,
                "{$bucket['date']} is not a Monday",
            );
        }

        // A week bucket averages only its logged days, so the week containing
        // the 2,000 kcal day reports 2,000 rather than 2,000 / 7.
        $this->assertSame(2000, $series->firstWhere('date', '2026-08-24')['calories']);
    }

    public function test_a_custom_range_is_honoured(): void
    {
        $user = $this->userWithTarget();

        Meal::factory()->for($user)->on('2026-08-10')->withTotals(1500)->create();

        $this->actingAs($user)->getJson('/api/analytics?from=2026-08-09&to=2026-08-11')
            ->assertOk()
            ->assertJsonPath('data.range.from', '2026-08-09')
            ->assertJsonPath('data.range.to', '2026-08-11')
            ->assertJsonPath('data.range.days', 3)
            ->assertJsonCount(3, 'data.series')
            ->assertJsonPath('data.summary.days_logged', 1);
    }

    public function test_an_invalid_range_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/analytics?range=decade')
            ->assertStatus(422)
            ->assertJsonValidationErrors('range');

        $this->actingAs($user)->getJson('/api/analytics?from=2026-08-11&to=2026-08-09')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        $this->actingAs($user)->getJson('/api/analytics?from=2020-01-01&to=2026-08-25')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_analytics_never_include_another_users_meals(): void
    {
        $user = $this->userWithTarget();
        $other = User::factory()->create();

        Meal::factory()->for($other)->on('2026-08-25')->withTotals(3000)->create();

        $this->actingAs($user)->getJson('/api/analytics?range=week')
            ->assertOk()
            ->assertJsonPath('data.summary.days_logged', 0)
            ->assertJsonPath('data.summary.totals.calories', 0);
    }

    public function test_a_soft_deleted_meal_leaves_the_series(): void
    {
        $user = $this->userWithTarget();

        $meal = Meal::factory()->for($user)->on('2026-08-25')->withTotals(1900)->create();

        $this->actingAs($user)->deleteJson("/api/meals/{$meal->id}")->assertOk();

        $this->actingAs($user)->getJson('/api/analytics?range=week')
            ->assertOk()
            ->assertJsonPath('data.summary.days_logged', 0)
            ->assertJsonPath('data.summary.total_meals', 0);
    }
}
