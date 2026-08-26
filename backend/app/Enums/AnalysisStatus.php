<?php

namespace App\Enums;

/**
 * Lifecycle of an uploaded meal photo as it moves through AI analysis.
 * Reserved for the meal-recognition phase; images start as `pending`.
 */
enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}