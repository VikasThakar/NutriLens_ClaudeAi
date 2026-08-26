<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/meal-images/{mealImage}/file  (signed)
 *
 * Meal photos are stored on a private disk, so they cannot be fetched by
 * guessing a URL. They are served through this route using Laravel's signed
 * URLs: the API hands out a short-lived signature alongside each meal, and an
 * <img> tag can load it without needing to send a bearer token.
 */
class MealImageController extends Controller
{
    public function show(MealImage $mealImage): Response
    {
        $disk = Storage::disk($mealImage->disk);

        if (! $disk->exists($mealImage->path)) {
            throw new NotFoundHttpException('Image not found.');
        }

        return $disk->response(
            $mealImage->path,
            null,
            [
                'Content-Type' => $mealImage->mime_type ?: 'image/jpeg',
                // Safe to cache hard: the signature expires long before this
                // does, and the underlying file never changes.
                'Cache-Control' => 'private, max-age=21600',
            ],
        );
    }
}
