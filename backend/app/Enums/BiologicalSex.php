<?php

namespace App\Enums;

/**
 * Used only by the Mifflin-St Jeor equation, which has a different constant
 * term for male and female bodies. `Unspecified` is deliberately supported:
 * the calculator then averages the two constants and says so, rather than
 * forcing the user to disclose something they would rather not.
 */
enum BiologicalSex: string
{
    case Female = 'female';
    case Male = 'male';
    case Unspecified = 'unspecified';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::Unspecified => 'Prefer not to say',
        };
    }

    /**
     * The constant term of the Mifflin-St Jeor equation:
     *   BMR = 10·kg + 6.25·cm − 5·age + constant
     */
    public function mifflinConstant(): float
    {
        return match ($this) {
            self::Female => -161.0,
            self::Male => 5.0,
            // Midpoint of the two, so an unspecified estimate lands between
            // them instead of silently assuming one.
            self::Unspecified => -78.0,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
