<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\GoalSource;
use App\Enums\GoalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The macro targets are optional: the user may skip the final onboarding
     * step, in which case sensible defaults for their goal are applied.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'goal_type' => ['required', Rule::enum(GoalType::class)],
            'calorie_target' => ['nullable', 'integer', 'min:800', 'max:10000'],
            'protein_target' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'carb_target' => ['nullable', 'integer', 'min:0', 'max:1500'],
            'fat_target' => ['nullable', 'integer', 'min:0', 'max:800'],

            // Provenance, so targets that came from the calculator during
            // onboarding are recorded as such rather than as hand-entered
            // ones. Optional: omitting it keeps the onboarding default.
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
            'goal_type.required' => 'Please choose a goal to continue.',
        ];
    }
}