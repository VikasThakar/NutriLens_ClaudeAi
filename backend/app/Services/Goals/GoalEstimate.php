<?php

namespace App\Services\Goals;

use App\Enums\ActivityLevel;
use App\Enums\BiologicalSex;
use App\Enums\GoalType;

/**
 * The output of the goal calculator: an estimate, plus every intermediate
 * figure it was built from.
 *
 * The intermediates are returned deliberately. A number a user cannot trace is
 * a number they cannot sensibly adjust, and every one of these is an estimate
 * they are expected to adjust.
 */
readonly class GoalEstimate
{
    /**
     * @param  array{protein:int, carbs:int, fat:int}  $macros
     * @param  array{protein:int, carbs:int, fat:int}  $macroPercent
     */
    public function __construct(
        public int $bmr,
        public int $maintenanceCalories,
        public int $targetCalories,
        public int $calorieAdjustment,
        public array $macros,
        public array $macroPercent,
        public float $proteinPerKg,
        public GoalType $goalType,
        public ActivityLevel $activityLevel,
        public BiologicalSex $biologicalSex,
        public string $formula,
        public bool $sexWasSpecified,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bmr' => $this->bmr,
            'maintenance_calories' => $this->maintenanceCalories,
            'calorie_adjustment' => $this->calorieAdjustment,
            'targets' => [
                'calorie_target' => $this->targetCalories,
                'protein_target' => $this->macros['protein'],
                'carb_target' => $this->macros['carbs'],
                'fat_target' => $this->macros['fat'],
            ],
            'macro_percent' => $this->macroPercent,
            'protein_per_kg' => $this->proteinPerKg,
            'goal_type' => $this->goalType->value,
            'goal_label' => $this->goalType->label(),
            'activity_level' => $this->activityLevel->value,
            'activity_label' => $this->activityLevel->label(),
            'activity_multiplier' => $this->activityLevel->multiplier(),
            'biological_sex' => $this->biologicalSex->value,
            'sex_was_specified' => $this->sexWasSpecified,
            'formula' => $this->formula,
            'is_estimate' => true,
        ];
    }
}
