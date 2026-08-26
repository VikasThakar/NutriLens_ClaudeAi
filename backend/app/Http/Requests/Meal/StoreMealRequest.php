<?php

namespace App\Http\Requests\Meal;

use App\Enums\MealSource;
use App\Enums\MealType;
use App\Models\MealItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Both AI-reviewed and manually entered meals arrive here — the difference
     * is `source` and whether the AI baseline fields are present.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxItems = (int) config('ai.limits.max_items', 12);

        return [
            'meal_name' => ['required', 'string', 'min:2', 'max:120'],
            'meal_type' => ['required', Rule::enum(MealType::class)],
            'source' => ['sometimes', Rule::enum(MealSource::class)],
            'consumed_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Overall AI confidence, only meaningful for an AI-sourced meal.
            'ai_confidence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'ai_provider' => ['sometimes', 'nullable', 'string', 'max:32'],
            'ai_model' => ['sometimes', 'nullable', 'string', 'max:64'],

            // Image uploaded during analysis, claimed when the meal is saved.
            'meal_image_id' => ['sometimes', 'nullable', 'integer'],

            'items' => ['required', 'array', 'min:1', "max:{$maxItems}"],
            'items.*.name' => ['required', 'string', 'min:1', 'max:120'],
            'items.*.brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'items.*.portion_amount' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'items.*.portion_unit' => ['required', 'string', 'min:1', 'max:24'],
            'items.*.calories' => ['required', 'numeric', 'min:0', 'max:10000'],
            'items.*.protein' => ['required', 'numeric', 'min:0', 'max:1000'],
            'items.*.carbs' => ['required', 'numeric', 'min:0', 'max:1500'],
            'items.*.fat' => ['required', 'numeric', 'min:0', 'max:1000'],

            'items.*.base_portion_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:10000'],
            'items.*.base_calories' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'items.*.base_protein' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'items.*.base_carbs' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1500'],
            'items.*.base_fat' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],

            'items.*.confidence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.is_ai_generated' => ['sometimes', 'boolean'],
            'items.*.was_edited' => ['sometimes', 'boolean'],
            'items.*.locked_macros' => ['sometimes', 'nullable', 'array'],
            'items.*.locked_macros.*' => ['string', Rule::in(MealItem::MACRO_FIELDS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meal_name.required' => 'Give this meal a name.',
            'items.required' => 'Add at least one food item.',
            'items.min' => 'Add at least one food item.',
            'items.*.name.required' => 'Every food item needs a name.',
            'items.*.portion_amount.gt' => 'Portion must be greater than zero.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'meal_name' => 'meal name',
            'meal_type' => 'meal type',
        ];
    }
}
