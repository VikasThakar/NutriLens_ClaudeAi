<?php

namespace App\Http\Resources;

use App\Models\WeeklyInsight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WeeklyInsight */
class WeeklyInsightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'week_start' => $this->week_start?->toDateString(),
            'week_end' => $this->week_end?->toDateString(),
            'headline' => $this->headline,
            'summary' => $this->summary,
            'observations' => $this->highlights ?? [],
            'suggestions' => $this->recommendations ?? [],
            'stats' => [
                'days_logged' => $this->days_logged,
                'meals_logged' => $this->meals_logged,
                'avg_calories' => $this->avg_calories,
                'avg_protein' => $this->avg_protein,
                'avg_carbs' => $this->avg_carbs,
                'avg_fat' => $this->avg_fat,
                'calorie_target' => $this->calorie_target,
                'days_close_to_target' => $this->days_close_to_target,
                // Percentage of logged days inside the tolerance band. Null
                // when there was no target to measure against.
                'days_close_percent' => $this->goal_adherence,
            ],
            // The previous week this summary was compared against, or null when
            // there was not enough data to compare.
            'comparison' => $this->comparison,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'ai_provider' => $this->ai_provider,
            'ai_model' => $this->ai_model,
        ];
    }
}
