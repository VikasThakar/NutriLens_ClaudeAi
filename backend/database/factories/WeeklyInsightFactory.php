<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WeeklyInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyInsight>
 */
class WeeklyInsightFactory extends Factory
{
    protected $model = WeeklyInsight::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => '2026-08-17',
            'week_end' => '2026-08-23',
            'headline' => 'A steady week',
            'summary' => 'You logged 5 of 7 days, averaging 2,000 kcal on the days you logged.',
            'highlights' => ['Weekdays were more consistent than the weekend.'],
            'recommendations' => [],
            'meals_logged' => 12,
            'days_logged' => 5,
            'days_close_to_target' => 3,
            'calorie_target' => 2000,
            'avg_calories' => 2000,
            'avg_protein' => 140,
            'avg_carbs' => 210,
            'avg_fat' => 65,
            'goal_adherence' => 60,
            'generated_at' => now(),
            'ai_provider' => 'fake',
            'ai_model' => 'nutrilens-fake-insights',
            'data_hash' => str_repeat('a', 64),
        ];
    }
}
