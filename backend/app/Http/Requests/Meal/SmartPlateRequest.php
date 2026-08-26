<?php

namespace App\Http\Requests\Meal;

use App\Models\MealItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The unsaved meal a Smart Plate analysis is about.
 *
 * The shape deliberately mirrors StoreMealRequest's `items`, because it *is*
 * the same draft — the review screen sends what it is holding, gets an analysis
 * back, and only later posts the same items to /api/meals. Reusing the shape
 * means a value that would be rejected on save is rejected here too, rather
 * than being analysed and then refused.
 *
 * The baseline and lock fields matter as much as the macros: without them the
 * backend cannot reproduce the frontend's portion scaling, and a suggestion's
 * predicted numbers would not survive the user tapping Apply.
 */
class SmartPlateRequest extends FormRequest
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
        $maxItems = (int) config('ai.limits.max_items', 12);

        return [
            /*
             * The meal being edited, when Smart Plate is opened on a meal that
             * is already saved. Its macros are in today's totals already, so
             * they have to come back out before "remaining" means anything.
             * Ownership is checked in the controller, not here.
             */
            'meal_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            // Allow more items than a save would, since an in-progress draft
            // can briefly hold an empty row the user is still filling in.
            'items' => ['present', 'array', "max:{$maxItems}"],
            'items.*.name' => ['present', 'nullable', 'string', 'max:120'],
            'items.*.portion_amount' => ['required', 'numeric', 'min:0', 'max:10000'],
            'items.*.portion_unit' => ['required', 'string', 'min:1', 'max:24'],

            'items.*.calories' => ['required', 'numeric', 'min:0', 'max:10000'],
            'items.*.protein' => ['required', 'numeric', 'min:0', 'max:1000'],
            'items.*.carbs' => ['required', 'numeric', 'min:0', 'max:1500'],
            'items.*.fat' => ['required', 'numeric', 'min:0', 'max:1000'],

            // The AI's original estimate, which portion scaling works from.
            'items.*.base_portion_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'items.*.base_calories' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'items.*.base_protein' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'items.*.base_carbs' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1500'],
            'items.*.base_fat' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],

            'items.*.confidence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.locked_macros' => ['sometimes', 'nullable', 'array'],
            'items.*.locked_macros.*' => ['string', Rule::in(MealItem::MACRO_FIELDS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A half-typed row has an empty name; that is a state to analyse, not a
        // validation failure, so it is normalised rather than rejected.
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        $this->merge([
            'items' => array_map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }

                $item['name'] = is_string($item['name'] ?? null) ? trim($item['name']) : '';

                return $item;
            }, $items),
        ]);
    }
}
