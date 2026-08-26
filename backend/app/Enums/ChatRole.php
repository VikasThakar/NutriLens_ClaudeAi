<?php

namespace App\Enums;

/**
 * Who wrote a turn in an AI Coach conversation.
 *
 * Deliberately only two values: the system prompt is server-side and is never
 * persisted, so a stored conversation can never be used to smuggle
 * instructions into a later request.
 */
enum ChatRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
