<?php

namespace Database\Factories;

use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Enums\MealType;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Meal>
 *
 * Exists for the Phase 3 aggregation tests, which need weeks of meals across
 * specific calendar dates. Feature tests that exercise the *saving* of a meal
 * still go through POST /api/meals — this factory is for the read side.
 */
class MealFactory extends Factory
{
    protected $model = Meal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $consumedAt = Carbon::now()->subHours(fake()->numberBetween(1, 8));

        return [
            'user_id' => User::factory(),
            'meal_name' => fake()->randomElement([
                'Chicken Rice Bowl',
                'Greek Yoghurt with Berries',
                'Beef Stir Fry',
                'Salmon Salad',
                'Porridge and Banana',
            ]),
            'meal_type' => fake()->randomElement(MealType::cases()),
            'source' => MealSource::Manual,
            'status' => MealStatus::Logged,
            'consumed_at' => $consumedAt,
            'consumed_on' => $consumedAt->toDateString(),
            'total_calories' => fake()->numberBetween(250, 900),
            'total_protein' => fake()->randomFloat(1, 10, 60),
            'total_carbs' => fake()->randomFloat(1, 10, 90),
            'total_fat' => fake()->randomFloat(1, 5, 40),
        ];
    }

    /**
     * Place the meal on a specific calendar date. `consumed_on` is set from the
     * same date so the denormalised day column never disagrees with the
     * timestamp — which is exactly the invariant the aggregations rely on.
     */
    public function on(string $date, int $hour = 12): static
    {
        return $this->state(fn () => [
            'consumed_at' => Carbon::parse($date)->setTime($hour, 0),
            'consumed_on' => $date,
        ]);
    }

    /** Fixed macro totals, for tests that assert on exact figures. */
    public function withTotals(int $calories, float $protein = 0, float $carbs = 0, float $fat = 0): static
    {
        return $this->state(fn () => [
            'total_calories' => $calories,
            'total_protein' => $protein,
            'total_carbs' => $carbs,
            'total_fat' => $fat,
        ]);
    }
}
