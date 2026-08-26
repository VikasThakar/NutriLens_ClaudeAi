<?php

namespace App\Http\Resources;

use App\Models\MealItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MealItem */
class MealItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'portion_amount' => $this->portion_amount,
            'portion_unit' => $this->portion_unit,
            'calories' => $this->calories,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'fat' => $this->fat,
            'fiber' => $this->fiber,
            'sugar' => $this->sugar,
            'sodium' => $this->sodium,

            // The AI baseline, so the client can keep scaling portions from the
            // original estimate after a reload.
            'base_portion_amount' => $this->base_portion_amount,
            'base_calories' => $this->base_calories,
            'base_protein' => $this->base_protein,
            'base_carbs' => $this->base_carbs,
            'base_fat' => $this->base_fat,

            'confidence' => $this->confidence,
            'is_ai_generated' => $this->is_ai_generated,
            'was_edited' => $this->was_edited,
            'locked_macros' => $this->locked_macros ?? [],
            'position' => $this->position,
        ];
    }
}
