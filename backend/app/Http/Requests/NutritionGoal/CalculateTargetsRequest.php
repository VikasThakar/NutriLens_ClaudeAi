<?php

namespace App\Http\Requests\NutritionGoal;

use App\Enums\ActivityLevel;
use App\Enums\BiologicalSex;
use App\Enums\GoalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalculateTargetsRequest extends FormRequest
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
            // The estimation formula is derived from adult populations, so the
            // bounds are the range it is actually meaningful over rather than
            // whatever a number field will accept.
            'age' => ['required', 'integer', 'min:16', 'max:100'],
            'height_cm' => ['required', 'integer', 'min:120', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:30', 'max:300'],
            'activity_level' => ['required', Rule::enum(ActivityLevel::class)],
            'goal_type' => ['required', Rule::enum(GoalType::class)],
            // Optional by design: the calculator explains why the equation asks
            // for it and works without it.
            'biological_sex' => ['sometimes', 'nullable', Rule::enum(BiologicalSex::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'age.min' => 'This estimate is only meaningful for adults, so 16 is the lowest age it accepts.',
            'height_cm.min' => 'Enter your height in centimetres.',
            'weight_kg.min' => 'Enter your weight in kilograms.',
            'activity_level.required' => 'Choose how active a typical week is.',
            'goal_type.required' => 'Choose what you are working toward.',
        ];
    }
}
