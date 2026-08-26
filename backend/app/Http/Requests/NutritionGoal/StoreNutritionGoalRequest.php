<?php

namespace App\Http\Requests\NutritionGoal;

use App\Enums\GoalSource;
use App\Enums\GoalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNutritionGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'goal_type' => ['required', Rule::enum(GoalType::class)],
            'calorie_target' => ['required', 'integer', 'min:800', 'max:10000'],
            'protein_target' => ['required', 'integer', 'min:0', 'max:1000'],
            'carb_target' => ['required', 'integer', 'min:0', 'max:1500'],
            'fat_target' => ['required', 'integer', 'min:0', 'max:800'],

            // Provenance, so the Goals screen can say whether these numbers
            // came from the calculator or were typed. Optional: an older client
            // that omits it still saves a valid goal.
            'source' => ['sometimes', Rule::enum(GoalSource::class)],
            'estimated_maintenance_calories' => ['sometimes', 'nullable', 'integer', 'min:800', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'calorie_target.min' => 'A daily calorie target below 800 kcal is not supported.',
            'goal_type.required' => 'Please choose a goal.',
        ];
    }
}
