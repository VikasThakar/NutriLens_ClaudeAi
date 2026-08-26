<?php

namespace App\Enums;

enum GoalType: string
{
    case LoseWeight = 'lose_weight';
    case MaintainWeight = 'maintain_weight';
    case BuildMuscle = 'build_muscle';
    case ImproveNutrition = 'improve_nutrition';

    public function label(): string
    {
        return match ($this) {
            self::LoseWeight => 'Lose Weight',
            self::MaintainWeight => 'Maintain Weight',
            self::BuildMuscle => 'Build Muscle',
            self::ImproveNutrition => 'Improve Nutrition',
        };
    }

    /**
     * Sensible starting macro split for each goal, used to pre-fill the
     * onboarding targets. The user can always override these.
     *
     * @return array{calories:int, protein:int, carbs:int, fat:int}
     */
    public function defaultTargets(): array
    {
        return match ($this) {
            self::LoseWeight => ['calories' => 1800, 'protein' => 140, 'carbs' => 160, 'fat' => 60],
            self::MaintainWeight => ['calories' => 2200, 'protein' => 130, 'carbs' => 240, 'fat' => 75],
            self::BuildMuscle => ['calories' => 2800, 'protein' => 190, 'carbs' => 320, 'fat' => 85],
            self::ImproveNutrition => ['calories' => 2000, 'protein' => 120, 'carbs' => 220, 'fat' => 70],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}