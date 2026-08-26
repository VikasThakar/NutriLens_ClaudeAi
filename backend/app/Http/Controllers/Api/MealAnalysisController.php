<?php

namespace App\Http\Controllers\Api;

use App\Enums\AnalysisStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meal\AnalyzeMealImageRequest;
use App\Models\MealImage;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\MealAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * POST /api/meals/analyze
 *
 * Accepts a photo, stores it against the authenticated user, sends it to the
 * configured vision provider, and returns a validated draft the user reviews.
 *
 * Nothing is written to `meals` here — a draft the user abandons should not
 * appear on their dashboard. The saved image is claimed by POST /api/meals.
 */
class MealAnalysisController extends Controller
{
    public function __construct(private readonly MealAnalysisService $analysis)
    {
    }

    public function store(AnalyzeMealImageRequest $request): JsonResponse
    {
        $user = $request->user();
        $upload = $request->file('image');

        // Store first: if analysis fails the user can retry, or save manually,
        // without having to take the photo again.
        $path = $upload->storeAs(
            "meals/{$user->id}",
            Str::ulid().'.'.$upload->extension(),
            'local',
        );

        if ($path === false) {
            return response()->json([
                'message' => 'The photo could not be saved. Please try again.',
            ], 500);
        }

        $dimensions = @getimagesize($upload->getRealPath());

        $image = MealImage::create([
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => Str::limit($upload->getClientOriginalName(), 255, ''),
            'mime_type' => $upload->getMimeType(),
            'size_bytes' => $upload->getSize(),
            'width' => $dimensions ? (int) $dimensions[0] : null,
            'height' => $dimensions ? (int) $dimensions[1] : null,
            'analysis_status' => AnalysisStatus::Processing,
        ]);

        try {
            $analysis = $this->analysis->analyzeUpload($upload);
        } catch (AiException $e) {
            $image->update([
                'analysis_status' => AnalysisStatus::Failed,
                'analysis_error' => Str::limit($e->getMessage(), 1000, ''),
            ]);

            return response()->json([
                'message' => $e->userMessage(),
                'retryable' => $e->retryable(),
                'data' => [
                    // Returned so the client can still save this photo with a
                    // manually entered meal.
                    'meal_image' => $this->imagePayload($image),
                ],
            ], $e->status());
        }

        $image->update([
            'analysis_status' => AnalysisStatus::Completed,
            'analysis_payload' => $analysis->toArray(),
            'analysis_error' => null,
            'analyzed_at' => now(),
        ]);

        Log::info('Meal analysed', [
            'user_id' => $user->id,
            'meal_image_id' => $image->id,
            'provider' => $analysis->provider,
            'items' => count($analysis->items),
            'confidence' => $analysis->confidence,
        ]);

        return response()->json([
            'message' => 'Analysis complete.',
            'data' => [
                'analysis' => $analysis->toArray(),
                'meal_image' => $this->imagePayload($image),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function imagePayload(MealImage $image): array
    {
        return [
            'id' => $image->id,
            'url' => URL::temporarySignedRoute(
                'meal-images.show',
                now()->addHours(6),
                ['mealImage' => $image->id],
            ),
            'width' => $image->width,
            'height' => $image->height,
        ];
    }
}
