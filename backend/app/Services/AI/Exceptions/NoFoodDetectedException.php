<?php

namespace App\Services\AI\Exceptions;

/** The model looked at the photo and reported no identifiable food. */
class NoFoodDetectedException extends AiException
{
    public function status(): int
    {
        return 422;
    }

    public function userMessage(): string
    {
        return 'No food could be identified in this photo. '
            .'Try a closer, better-lit shot of the plate.';
    }

    /**
     * Re-running the same photo will reach the same conclusion, so the UI should
     * steer the user to a different image rather than offering "try again".
     */
    public function retryable(): bool
    {
        return false;
    }
}
