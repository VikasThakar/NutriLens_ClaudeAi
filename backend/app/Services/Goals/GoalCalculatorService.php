<?php

namespace App\Services\Goals;

use App\Enums\ActivityLevel;
use App\Enums\BiologicalSex;
use App\Enums\GoalType;

/**
 * Estimates daily calorie and macro targets from body metrics.
 *
 * Standard, widely published estimation formulas — nothing bespoke:
 *
 *  - BMR via **Mifflin-St Jeor** (1990), the equation most commonly used for
 *    this purpose:  10·kg + 6.25·cm − 5·age + c,  where c is +5 for male
 *    bodies and −161 for female bodies.
 *  - Maintenance (TDEE) = BMR × a conventional activity multiplier.
 *  - A goal-dependent calorie offset, expressed as a share of maintenance so
 *    the deficit or surplus scales with body size rather than being a flat
 *    500 kcal for everyone.
 *  - Protein anchored to body weight (g/kg), fat to a share of calories, and
 *    carbohydrate taking whatever energy is left.
 *
 * Every figure is an estimate of an average. It is not medical advice, it is
 * not personalised to any individual's physiology, and the app says so
 * wherever these numbers appear.
 */
class GoalCalculatorService
{
    /** Deficit / surplus as a fraction of maintenance calories. */
    private const CALORIE_ADJUSTMENT = [
        GoalType::LoseWeight->value => -0.18,
        GoalType::MaintainWeight->value => 0.0,
        GoalType::BuildMuscle->value => 0.10,
        GoalType::ImproveNutrition->value => 0.0,
    ];

    /** Grams of protein per kg of body weight. */
    private const PROTEIN_PER_KG = [
        GoalType::LoseWeight->value => 2.0,    // higher, to protect lean mass in a deficit
        GoalType::MaintainWeight->value => 1.6,
        GoalType::BuildMuscle->value => 1.9,
        GoalType::ImproveNutrition->value => 1.6,
    ];

    /** Share of total calories from fat. */
    private const FAT_SHARE = [
        GoalType::LoseWeight->value => 0.28,
        GoalType::MaintainWeight->value => 0.30,
        GoalType::BuildMuscle->value => 0.25,
        GoalType::ImproveNutrition->value => 0.30,
    ];

    /**
     * Floors below which a calculated target is clamped. A deficit that lands
     * a small, sedentary person at 1,100 kcal is arithmetically correct and
     * not something this app should suggest.
     */
    private const MIN_CALORIES = 1200;

    private const MAX_CALORIES = 6000;

    /** kcal per gram. */
    private const ENERGY = ['protein' => 4, 'carbs' => 4, 'fat' => 9];

    public function estimate(
        int $age,
        int $heightCm,
        float $weightKg,
        ActivityLevel $activityLevel,
        GoalType $goalType,
        BiologicalSex $biologicalSex = BiologicalSex::Unspecified,
    ): GoalEstimate {
        $bmr = (10 * $weightKg)
            + (6.25 * $heightCm)
            - (5 * $age)
            + $biologicalSex->mifflinConstant();

        $bmr = max(800.0, $bmr);

        $maintenance = $bmr * $activityLevel->multiplier();

        $adjustmentRatio = self::CALORIE_ADJUSTMENT[$goalType->value];
        $target = $maintenance * (1 + $adjustmentRatio);

        // Round to the nearest 10 kcal: the precision of the underlying
        // formula does not justify a figure like 2,347.
        $target = $this->clamp((int) (round($target / 10) * 10), self::MIN_CALORIES, self::MAX_CALORIES);

        $macros = $this->macrosFor($target, $weightKg, $goalType);

        return new GoalEstimate(
            bmr: (int) round($bmr),
            maintenanceCalories: (int) (round($maintenance / 10) * 10),
            targetCalories: $target,
            calorieAdjustment: $target - (int) (round($maintenance / 10) * 10),
            macros: $macros,
            macroPercent: $this->macroPercent($macros, $target),
            proteinPerKg: self::PROTEIN_PER_KG[$goalType->value],
            goalType: $goalType,
            activityLevel: $activityLevel,
            biologicalSex: $biologicalSex,
            formula: 'Mifflin-St Jeor',
            sexWasSpecified: $biologicalSex !== BiologicalSex::Unspecified,
        );
    }

    /**
     * Protein from body weight, fat from a share of calories, carbohydrate
     * from the remainder.
     *
     * @return array{protein:int, carbs:int, fat:int}
     */
    private function macrosFor(int $calories, float $weightKg, GoalType $goalType): array
    {
        $protein = (int) round($weightKg * self::PROTEIN_PER_KG[$goalType->value]);
        $fat = (int) round(($calories * self::FAT_SHARE[$goalType->value]) / self::ENERGY['fat']);

        $spent = ($protein * self::ENERGY['protein']) + ($fat * self::ENERGY['fat']);

        // Protein and fat can in principle exceed the calorie budget for a
        // heavy person on an aggressive deficit. Trim fat first — protein is
        // the target worth protecting — and never return a negative carb goal.
        if ($spent > $calories) {
            $overshoot = $spent - $calories;
            $fat = max(20, $fat - (int) ceil($overshoot / self::ENERGY['fat']));
            $spent = ($protein * self::ENERGY['protein']) + ($fat * self::ENERGY['fat']);
        }

        $carbs = max(0, (int) round(($calories - $spent) / self::ENERGY['carbs']));

        return ['protein' => $protein, 'carbs' => $carbs, 'fat' => $fat];
    }

    /**
     * @param  array{protein:int, carbs:int, fat:int}  $macros
     * @return array{protein:int, carbs:int, fat:int}
     */
    private function macroPercent(array $macros, int $calories): array
    {
        if ($calories <= 0) {
            return ['protein' => 0, 'carbs' => 0, 'fat' => 0];
        }

        return [
            'protein' => (int) round(($macros['protein'] * self::ENERGY['protein'] / $calories) * 100),
            'carbs' => (int) round(($macros['carbs'] * self::ENERGY['carbs'] / $calories) * 100),
            'fat' => (int) round(($macros['fat'] * self::ENERGY['fat'] / $calories) * 100),
        ];
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
