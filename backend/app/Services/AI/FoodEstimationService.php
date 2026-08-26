<?php

namespace App\Services\AI;

use App\Enums\PortionUnit;
use App\Services\AI\Contracts\FoodNutritionEstimator;
use App\Services\AI\Data\AnalyzedFoodItem;
use App\Services\AI\Data\AnalyzedMeal;
use App\Services\AI\Data\FoodQuery;
use App\Services\AI\Exceptions\AiResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turns a partner's structured food list into a validated nutrition estimate.
 *
 * The result is an AnalyzedMeal — deliberately the same object the photo
 * pipeline produces — so both partner endpoints answer in one shape and a
 * caller can switch between them without touching their parser.
 *
 * As with vision, the provider is only trusted to return *something*. What
 * leaves this class has been schema-checked, range-clamped, unit-normalised and
 * re-aligned with the request: a model that drops an item, reorders them or
 * rewrites a portion cannot corrupt the response.
 */
class FoodEstimationService
{
    public function __construct(
        private readonly FoodNutritionEstimator $estimator,
        private readonly FoodEstimationPrompt $prompt,
    ) {
    }

    public function estimator(): FoodNutritionEstimator
    {
        return $this->estimator;
    }

    /**
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function estimate(FoodQuery $query): AnalyzedMeal
    {
        return $this->validate($this->estimator->estimate($query), $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    private function validate(array $payload, FoodQuery $query): AnalyzedMeal
    {
        $maxCalories = (int) config('ai.limits.max_calories_per_item', 5000);
        $maxMacro = (int) config('ai.limits.max_grams_per_macro', 1000);

        $validator = Validator::make($payload, [
            'meal_name' => ['required', 'string', 'max:120'],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'notes' => ['present', 'nullable', 'string', 'max:500'],

            'items' => ['present', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.portion_amount' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'items.*.portion_unit' => ['required', 'string', 'max:24'],
            'items.*.calories' => ['required', 'numeric', 'min:0', "max:{$maxCalories}"],
            'items.*.protein' => ['required', 'numeric', 'min:0', "max:{$maxMacro}"],
            'items.*.carbs' => ['required', 'numeric', 'min:0', "max:{$maxMacro}"],
            'items.*.fat' => ['required', 'numeric', 'min:0', "max:{$maxMacro}"],
            'items.*.confidence' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        if ($validator->fails()) {
            Log::warning('Food estimation failed validation', [
                'provider' => $this->estimator->providerName(),
                'errors' => $validator->errors()->toArray(),
            ]);

            throw new AiResponseException('The AI response did not match the expected schema.');
        }

        /** @var array{meal_name:string, confidence:float|int, notes:?string, items:array<int, array<string, mixed>>} $clean */
        $clean = $validator->validated();

        $returned = array_values($clean['items']);
        $requested = $query->items;

        // The contract with the partner is one result per food they sent, in
        // their order. A model that returns a different count has not answered
        // the question that was asked.
        if (count($returned) !== count($requested)) {
            Log::warning('Food estimation returned the wrong number of items', [
                'provider' => $this->estimator->providerName(),
                'requested' => count($requested),
                'returned' => count($returned),
            ]);

            throw new AiResponseException('The AI returned a different set of foods than were requested.');
        }

        $items = [];

        foreach ($returned as $index => $raw) {
            $request = $requested[$index];

            $items[] = new AnalyzedFoodItem(
                // The partner's own name and portion win. The estimate is *for*
                // what they asked about, so echoing their input back verbatim is
                // the only version that can be trusted to match.
                name: $this->cleanName($request['name']),
                portionAmount: round((float) $request['portion_amount'], 2),
                portionUnit: $this->normalizeUnit($request['portion_unit']),
                calories: (int) round((float) $raw['calories']),
                protein: round((float) $raw['protein'], 1),
                carbs: round((float) $raw['carbs'], 1),
                fat: round((float) $raw['fat'], 1),
                confidence: round((float) $raw['confidence'], 3),
            );
        }

        return new AnalyzedMeal(
            mealName: $this->cleanName($query->mealName ?? (string) $clean['meal_name']),
            confidence: round((float) $clean['confidence'], 3),
            items: $items,
            notes: $this->cleanNotes($clean['notes'] ?? null),
            provider: $this->estimator->providerName(),
            model: $this->estimator->modelName(),
        );
    }

    private function normalizeUnit(string $unit): string
    {
        $normalized = Str::lower(trim($unit));

        return in_array($normalized, PortionUnit::values(), true)
            ? $normalized
            : (Str::limit($normalized, 24, '') ?: 'serving');
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return Str::limit($name, 120, '');
    }

    private function cleanNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : Str::limit($notes, 500, '');
    }
}
