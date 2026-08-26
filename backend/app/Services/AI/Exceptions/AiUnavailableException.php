<?php

namespace App\Services\AI\Exceptions;

/** The provider was unreachable, timed out, rate-limited, or returned 5xx. */
class AiUnavailableException extends AiException
{
    public function status(): int
    {
        return 503;
    }

    public function userMessage(): string
    {
        return 'The AI service is temporarily unavailable. '
            .'Please try again in a moment.';
    }
}
