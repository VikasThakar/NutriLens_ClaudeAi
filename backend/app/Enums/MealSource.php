<?php

namespace App\Enums;

/**
 * How a meal entry came to exist. AI-sourced meals are produced by the
 * photo analysis pipeline (a later phase); manual meals are typed by hand.
 */
enum MealSource: string
{
    case AiPhoto = 'ai_photo';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}