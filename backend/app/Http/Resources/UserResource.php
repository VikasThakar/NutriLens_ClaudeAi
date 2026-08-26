<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_path
                ? asset('storage/'.$this->avatar_path)
                : null,
            'timezone' => $this->timezone,
            'has_onboarded' => $this->onboarded_at !== null,
            'onboarded_at' => $this->onboarded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Omitted entirely when the relation was not eager-loaded, so the
            // client can never mistake "not fetched" for "no goal set".
            'nutrition_goal' => $this->whenLoaded(
                'activeNutritionGoal',
                fn () => $this->activeNutritionGoal
                    ? NutritionGoalResource::make($this->activeNutritionGoal)
                    : null
            ),
        ];
    }
}