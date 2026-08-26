<?php

namespace App\Enums;

/** How a nutrition goal's targets were arrived at. */
enum GoalSource: string
{
    case Onboarding = 'onboarding';
    case Manual = 'manual';
    case Calculator = 'calculator';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Set during onboarding',
            self::Manual => 'Entered manually',
            self::Calculator => 'From the goal calculator',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
