<?php

namespace App\Services\AI;

use App\Services\AI\Data\PreparedImage;
use App\Services\AI\Exceptions\AiResponseException;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Normalises an uploaded photo before it is sent to a vision model:
 * re-orients it from EXIF, downscales the long edge, and re-encodes as JPEG.
 *
 * This is not cosmetic. Phone photos are routinely 4000px and 6 MB; sending
 * them raw costs several times more per analysis for no gain in recognition
 * quality, and risks bumping into provider payload limits.
 */
class MealImagePreparer
{
    public function prepare(UploadedFile $file): PreparedImage
    {
        $binary = file_get_contents($file->getRealPath());

        if ($binary === false || $binary === '') {
            throw new AiResponseException('Uploaded image could not be read.');
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            // Reaches here for formats GD cannot decode — most often HEIC from
            // an iPhone that was uploaded without conversion.
            throw new UnsupportedImageException(
                'This image format could not be processed.'
            );
        }

        try {
            $image = $this->autoOrient($image, $file->getRealPath());
            $image = $this->downscale($image);

            $width = imagesx($image);
            $height = imagesy($image);

            ob_start();
            $ok = imagejpeg($image, null, (int) config('ai.image.jpeg_quality', 82));
            $jpeg = (string) ob_get_clean();

            if (! $ok || $jpeg === '') {
                throw new RuntimeException('JPEG re-encoding failed.');
            }

            return new PreparedImage(
                binary: $jpeg,
                mimeType: 'image/jpeg',
                width: $width,
                height: $height,
            );
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Phone cameras store rotation in EXIF rather than rotating the pixels.
     * Without this, a portrait photo reaches the model on its side.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function autoOrient($image, string $path)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        $rotation = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotation === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $rotation, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function downscale($image)
    {
        $maxEdge = max(256, (int) config('ai.image.max_edge', 1568));

        $width = imagesx($image);
        $height = imagesy($image);
        $longEdge = max($width, $height);

        if ($longEdge <= $maxEdge) {
            return $image;
        }

        $scale = $maxEdge / $longEdge;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagescale($image, $newWidth, $newHeight, IMG_BICUBIC);

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }
}
