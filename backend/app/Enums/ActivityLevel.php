<?php

namespace App\Enums;

/**
 * Activity multipliers applied to BMR to estimate total daily energy
 * expenditure. The values are the conventional Harris-Benedict / Mifflin
 * multipliers used throughout the nutrition literature.
 */
enum ActivityLevel: string
{
    case Sedentary = 'sedentary';
    case Light = 'light';
    case Moderate = 'moderate';
    case Active = 'active';
    case VeryActive = 'very_active';

    public function label(): string
    {
        return match ($this) {
            self::Sedentary => 'Sedentary',
            self::Light => 'Lightly active',
            self::Moderate => 'Moderately active',
            self::Active => 'Very active',
            self::VeryActive => 'Extremely active',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sedentary => 'Desk work, little deliberate exercise',
            self::Light => 'Light exercise 1–3 days a week',
            self::Moderate => 'Moderate exercise 3–5 days a week',
            self::Active => 'Hard exercise 6–7 days a week',
            self::VeryActive => 'Physical job or twice-daily training',
        };
    }

    public function multiplier(): float
    {
        return match ($this) {
            self::Sedentary => 1.2,
            self::Light => 1.375,
            self::Moderate => 1.55,
            self::Active => 1.725,
            self::VeryActive => 1.9,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
