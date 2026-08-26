<?php

namespace App\Enums;

/**
 * A meal is a `draft` while the user is still reviewing/editing AI results,
 * and becomes `logged` once they save it against their daily totals.
 */
enum MealStatus: string
{
    case Draft = 'draft';
    case Logged = 'logged';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}