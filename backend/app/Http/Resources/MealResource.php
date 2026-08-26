<?php

namespace App\Http\Resources;

use App\Models\Meal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin Meal */
class MealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meal_name' => $this->meal_name,
            'meal_type' => $this->meal_type->value,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'consumed_at' => $this->consumed_at?->toIso8601String(),
            'consumed_on' => $this->consumed_on?->toDateString(),
            'totals' => [
                'calories' => $this->total_calories,
                'protein' => $this->total_protein,
                'carbs' => $this->total_carbs,
                'fat' => $this->total_fat,
            ],
            'ai_confidence' => $this->ai_confidence,
            'ai_provider' => $this->ai_provider,
            'ai_model' => $this->ai_model,
            'notes' => $this->notes,
            'image_url' => $this->imageUrl(),
            'item_count' => $this->whenCounted('items'),
            'items' => MealItemResource::collection($this->whenLoaded('items')),
        ];
    }

    /**
     * A short-lived signed URL. Meal photos live on a private disk, so they are
     * served through an expiring, signature-checked route rather than being
     * publicly reachable by guessing a filename.
     */
    private function imageUrl(): ?string
    {
        if (! $this->relationLoaded('image') || $this->image === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'meal-images.show',
            now()->addHours(6),
            ['mealImage' => $this->image->id],
        );
    }
}
