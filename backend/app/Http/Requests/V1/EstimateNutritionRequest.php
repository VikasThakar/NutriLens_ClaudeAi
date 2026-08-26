<?php

namespace App\Http\Requests\V1;

use App\Enums\PortionUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateNutritionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('api_key') !== null;
    }

    /**
     * Every field is validated, including the ones a partner might reasonably
     * expect to be lenient. A silently coerced portion is worse than a 422: the
     * partner would get a confident number for a quantity they did not send.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxItems = (int) config('ai.estimation.max_items', 20);

        return [
            'meal_name' => ['sometimes', 'nullable', 'string', 'max:120'],

            'items' => ['required', 'array', 'min:1', "max:{$maxItems}"],
            'items.*' => ['required', 'array'],
            'items.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'items.*.brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'items.*.portion_amount' => ['required', 'numeric', 'gt:0', 'max:10000'],
            // Constrained to the documented set rather than free text: an
            // unrecognised unit cannot be estimated against, and guessing what
            // "1 dollop" means is not something this API should do quietly.
            'items.*.portion_unit' => ['required', 'string', Rule::in(PortionUnit::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $units = implode(', ', PortionUnit::values());
        $maxItems = (int) config('ai.estimation.max_items', 20);

        return [
            'items.required' => 'An `items` array with at least one food is required.',
            'items.min' => 'Include at least one food in `items`.',
            'items.max' => "A single request may contain at most {$maxItems} foods.",
            'items.*.name.required' => 'Each item needs a `name`.',
            'items.*.name.min' => 'Each item `name` must be at least 2 characters.',
            'items.*.portion_amount.required' => 'Each item needs a `portion_amount`.',
            'items.*.portion_amount.gt' => 'Each `portion_amount` must be greater than 0.',
            'items.*.portion_unit.required' => 'Each item needs a `portion_unit`.',
            'items.*.portion_unit.in' => "`portion_unit` must be one of: {$units}.",
        ];
    }

    /**
     * The validated food list, normalised into the shape FoodQuery expects.
     *
     * @return list<array{name:string, portion_amount:float, portion_unit:string, brand:?string}>
     */
    public function foods(): array
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $this->validated('items');

        return array_values(array_map(fn (array $item) => [
            'name' => trim((string) $item['name']),
            'portion_amount' => (float) $item['portion_amount'],
            'portion_unit' => (string) $item['portion_unit'],
            'brand' => isset($item['brand']) && trim((string) $item['brand']) !== ''
                ? trim((string) $item['brand'])
                : null,
        ], $items));
    }

    public function mealName(): ?string
    {
        $name = $this->validated('meal_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
