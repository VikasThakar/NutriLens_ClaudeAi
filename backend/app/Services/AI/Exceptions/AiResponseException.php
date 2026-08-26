<?php

namespace App\Services\AI\Exceptions;

/**
 * The provider answered, but the payload was not a usable meal analysis —
 * malformed JSON, a schema violation, or nonsense values.
 */
class AiResponseException extends AiException
{
    public function status(): int
    {
        return 502;
    }

    public function userMessage(): string
    {
        return 'The AI could not produce a reliable reading of this photo. '
            .'Try a clearer, better-lit shot, or add the meal manually.';
    }
}
