<?php

namespace App\Http\Requests\Meal;

use App\Enums\MealType;
use App\Models\MealItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMealRequest extends FormRequest
{
    /**
     * Ownership is enforced by the MealPolicy on the route, so by the time this
     * runs the caller is known to own the meal.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Every field is optional, but `items` — when present — must be the
     * complete list: the service replaces them wholesale.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxItems = (int) config('ai.limits.max_items', 12);

        return [
            'meal_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'meal_type' => ['sometimes', Rule::enum(MealType::class)],
            'consumed_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'items' => ['sometimes', 'array', 'min:1', "max:{$maxItems}"],
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
            'items.min' => 'A meal needs at least one food item. Delete the meal instead.',
        ];
    }
}
