<?php

namespace App\Services\AI\Exceptions;

/** The provider is missing a key, or names a driver that does not exist. */
class AiConfigurationException extends AiException
{
    public function status(): int
    {
        return 503;
    }

    public function userMessage(): string
    {
        return 'AI analysis is not configured on this server yet. '
            .'You can still add this meal manually.';
    }

    public function retryable(): bool
    {
        return false;
    }
}
