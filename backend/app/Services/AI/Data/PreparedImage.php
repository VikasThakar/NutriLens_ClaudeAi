<?php

namespace App\Services\AI\Data;

/**
 * An uploaded photo, normalised and ready to send upstream: re-oriented,
 * downscaled and re-encoded as JPEG.
 */
final readonly class PreparedImage
{
    public function __construct(
        public string $binary,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {
    }

    public function base64(): string
    {
        return base64_encode($this->binary);
    }

    public function dataUri(): string
    {
        return 'data:'.$this->mimeType.';base64,'.$this->base64();
    }

    public function sizeBytes(): int
    {
        return strlen($this->binary);
    }
}
