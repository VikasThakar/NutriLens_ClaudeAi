<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HistoryTest extends TestCase
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

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/history/day')->assertUnauthorized();
        $this->getJson('/api/history/calendar')->assertUnauthorized();
    }

    public function test_the_day_view_defaults_to_today(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/history/day')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-08-25')
            ->assertJsonPath('data.is_today', true)
            ->assertJsonPath('data.is_future', false)
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.totals.calories', 0)
            ->assertJsonCount(0, 'data.meals');
    }

    public function test_a_specific_day_returns_its_meals_and_totals(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->withCalorieTarget(2000)->create();

        Meal::factory()->for($user)->on('2026-08-20', 8)->withTotals(400, 20, 50, 10)->create();
        Meal::factory()->for($user)->on('2026-08-20', 19)->withTotals(700, 45, 60, 25)->create();
        Meal::factory()->for($user)->on('2026-08-21', 12)->withTotals(900)->create();

        $response = $this->actingAs($user)->getJson('/api/history/day?date=2026-08-20')->assertOk();

        $response->assertJsonPath('data.date', '2026-08-20')
            ->assertJsonPath('data.is_today', false)
            ->assertJsonPath('data.meal_count', 2)
            ->assertJsonPath('data.totals.calories', 1100)
            ->assertJsonPath('data.totals.protein', 65)
            ->assertJsonPath('data.remaining.calories', 900)
            ->assertJsonPath('data.goal.calorie_target', 2000)
            ->assertJsonCount(2, 'data.meals');

        // Ordered by time eaten, so the day reads top to bottom.
        $times = collect($response->json('data.meals'))->pluck('consumed_at')->all();
        $this->assertTrue($times[0] < $times[1]);
    }

    public function test_meals_come_back_with_their_items(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/meals', [
            'meal_name' => 'Porridge and Banana',
            'meal_type' => 'breakfast',
            'consumed_at' => '2026-08-24T07:30:00Z',
            'items' => [
                ['name' => 'Porridge', 'portion_amount' => 250, 'portion_unit' => 'g', 'calories' => 220, 'protein' => 8, 'carbs' => 38, 'fat' => 4],
                ['name' => 'Banana', 'portion_amount' => 1, 'portion_unit' => 'piece', 'calories' => 105, 'protein' => 1.3, 'carbs' => 27, 'fat' => 0.4],
            ],
        ])->assertCreated();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-24')
            ->assertOk()
            ->assertJsonPath('data.meal_count', 1)
            ->assertJsonPath('data.totals.calories', 325)
            ->assertJsonCount(2, 'data.meals.0.items');
    }

    public function test_the_day_view_reports_the_nearest_logged_days_either_side(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-10')->create();
        Meal::factory()->for($user)->on('2026-08-20')->create();
        Meal::factory()->for($user)->on('2026-08-25')->create();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-20')
            ->assertOk()
            ->assertJsonPath('data.previous_logged_date', '2026-08-10')
            ->assertJsonPath('data.next_logged_date', '2026-08-25');
    }

    public function test_a_days_own_meals_are_not_reported_as_the_next_day(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-20', 8)->create();
        Meal::factory()->for($user)->on('2026-08-20', 20)->create();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-20')
            ->assertOk()
            ->assertJsonPath('data.previous_logged_date', null)
            ->assertJsonPath('data.next_logged_date', null);
    }

    public function test_an_empty_day_between_two_logged_days_is_navigable(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-18')->create();
        Meal::factory()->for($user)->on('2026-08-22')->create();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-20')
            ->assertOk()
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.previous_logged_date', '2026-08-18')
            ->assertJsonPath('data.next_logged_date', '2026-08-22');
    }

    public function test_a_future_date_is_flagged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-30')
            ->assertOk()
            ->assertJsonPath('data.is_future', true)
            ->assertJsonPath('data.meal_count', 0);
    }

    public function test_a_malformed_date_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/history/day?date=25-08-2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->actingAs($user)->getJson('/api/history/calendar?month=2026-13')
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
    }

    public function test_the_day_view_never_shows_another_users_meals(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Meal::factory()->for($other)->on('2026-08-20')->withTotals(2500)->create();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-20')
            ->assertOk()
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.totals.calories', 0)
            ->assertJsonPath('data.previous_logged_date', null)
            ->assertJsonPath('data.next_logged_date', null);
    }

    public function test_the_calendar_reports_every_day_of_the_month(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-03')->withTotals(1900)->create();
        Meal::factory()->for($user)->on('2026-08-03')->withTotals(400)->create();
        Meal::factory()->for($user)->on('2026-08-17')->withTotals(2100)->create();

        $response = $this->actingAs($user)->getJson('/api/history/calendar?month=2026-08')->assertOk();

        $response->assertJsonPath('data.month', '2026-08')
            ->assertJsonPath('data.days_logged', 2)
            ->assertJsonPath('data.total_meals', 3)
            ->assertJsonCount(31, 'data.days');

        $days = collect($response->json('data.days'))->keyBy('date');

        $this->assertSame(2300, $days['2026-08-03']['calories']);
        $this->assertSame(2, $days['2026-08-03']['meals']);
        $this->assertTrue($days['2026-08-17']['logged']);
        $this->assertFalse($days['2026-08-04']['logged']);
        $this->assertSame('2026-08-31', $days->keys()->last());
    }

    public function test_the_calendar_defaults_to_the_current_month(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/history/calendar')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-08')
            ->assertJsonCount(31, 'data.days');
    }

    public function test_a_february_calendar_has_the_right_number_of_days(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/history/calendar?month=2026-02')
            ->assertOk()
            ->assertJsonCount(28, 'data.days');
    }

    public function test_a_meal_can_be_edited_and_deleted_from_a_past_day(): void
    {
        $user = User::factory()->create();

        $created = $this->actingAs($user)->postJson('/api/meals', [
            'meal_name' => 'Salmon Salad',
            'meal_type' => 'lunch',
            'consumed_at' => '2026-08-19T12:30:00Z',
            'items' => [
                ['name' => 'Salmon', 'portion_amount' => 150, 'portion_unit' => 'g', 'calories' => 300, 'protein' => 34, 'carbs' => 0, 'fat' => 18],
            ],
        ])->assertCreated()->json('data.id');

        $this->actingAs($user)->putJson("/api/meals/{$created}", [
            'items' => [
                ['name' => 'Salmon', 'portion_amount' => 200, 'portion_unit' => 'g', 'calories' => 400, 'protein' => 45, 'carbs' => 0, 'fat' => 24],
            ],
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-19')
            ->assertOk()
            ->assertJsonPath('data.totals.calories', 400);

        $this->actingAs($user)->deleteJson("/api/meals/{$created}")->assertOk();

        $this->actingAs($user)->getJson('/api/history/day?date=2026-08-19')
            ->assertOk()
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.totals.calories', 0);
    }
}
