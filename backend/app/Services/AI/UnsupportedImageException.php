<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AiException;

/**
 * The file is a real image but this server cannot decode it — in practice,
 * HEIC/HEIF from an iPhone uploaded without conversion.
 */
class UnsupportedImageException extends AiException
{
    public function status(): int
    {
        return 422;
    }

    public function userMessage(): string
    {
        return 'This image format is not supported. '
            .'Please upload a JPEG, PNG or WebP photo.';
    }

    public function retryable(): bool
    {
        return false;
    }
}
