<?php

namespace App\Services;

use App\Enums\GoalSource;
use App\Enums\GoalType;
use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NutritionGoalService
{
    /**
     * Set (or replace) the user's active nutrition goal.
     *
     * Any previously active goal is retired rather than overwritten, so a
     * user's goal history stays intact for future progress reporting.
     *
     * @param  array{goal_type:string, calorie_target?:int|null, protein_target?:int|null, carb_target?:int|null, fat_target?:int|null, source?:string|null, estimated_maintenance_calories?:int|null}  $attributes
     */
    public function setActiveGoal(
        User $user,
        array $attributes,
        GoalSource $defaultSource = GoalSource::Manual,
    ): NutritionGoal {
        $goalType = GoalType::from($attributes['goal_type']);
        $defaults = $goalType->defaultTargets();

        $targets = [
            'calorie_target' => $attributes['calorie_target'] ?? $defaults['calories'],
            'protein_target' => $attributes['protein_target'] ?? $defaults['protein'],
            'carb_target' => $attributes['carb_target'] ?? $defaults['carbs'],
            'fat_target' => $attributes['fat_target'] ?? $defaults['fat'],
        ];

        $source = isset($attributes['source'])
            ? GoalSource::from($attributes['source'])
            : $defaultSource;

        return DB::transaction(function () use ($user, $goalType, $targets, $source, $attributes) {
            $user->nutritionGoals()->where('is_active', true)->update(['is_active' => false]);

            return $user->nutritionGoals()->create([
                'goal_type' => $goalType,
                ...$targets,
                'source' => $source,
                'estimated_maintenance_calories' => $attributes['estimated_maintenance_calories'] ?? null,
                'is_active' => true,
                'effective_from' => now($user->tz())->toDateString(),
            ]);
        });
    }
}
