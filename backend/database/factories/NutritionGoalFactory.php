<?php

namespace Database\Factories;

use App\Enums\GoalSource;
use App\Enums\GoalType;
use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NutritionGoal>
 */
class NutritionGoalFactory extends Factory
{
    protected $model = NutritionGoal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'goal_type' => GoalType::MaintainWeight,
            'calorie_target' => 2200,
            'protein_target' => 130,
            'carb_target' => 240,
            'fat_target' => 75,
            'source' => GoalSource::Manual,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
        ];
    }

    public function withCalorieTarget(int $calories): static
    {
        return $this->state(fn () => ['calorie_target' => $calories]);
    }
}
