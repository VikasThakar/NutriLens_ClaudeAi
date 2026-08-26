<?php

namespace App\Http\Resources;

use App\Models\NutritionGoal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NutritionGoal */
class NutritionGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goal_type' => $this->goal_type->value,
            'goal_label' => $this->goal_type->label(),
            'calorie_target' => $this->calorie_target,
            'protein_target' => $this->protein_target,
            'carb_target' => $this->carb_target,
            'fat_target' => $this->fat_target,
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            // Present only when the calculator produced these targets — the
            // maintenance figure they were derived from.
            'estimated_maintenance_calories' => $this->estimated_maintenance_calories,
            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
