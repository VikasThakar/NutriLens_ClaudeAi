<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * Base class for every failure in the vision pipeline. Each subclass carries
 * the HTTP status and the user-facing message the API should return, so
 * controllers never have to translate provider errors themselves.
 */
abstract class AiException extends RuntimeException
{
    /** HTTP status the API should respond with. */
    public function status(): int
    {
        return 502;
    }

    /** Message safe to show a user — never leaks keys, URLs or stack detail. */
    abstract public function userMessage(): string;

    /** Whether retrying the same image could plausibly succeed. */
    public function retryable(): bool
    {
        return true;
    }
}
