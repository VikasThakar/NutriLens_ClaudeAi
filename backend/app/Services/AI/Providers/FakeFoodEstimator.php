<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\FoodNutritionEstimator;
use App\Services\AI\Data\FoodQuery;
use App\Services\Nutrition\FoodNutritionTable;
use Illuminate\Support\Str;

/**
 * Offline driver — AI_PROVIDER=fake.
 *
 * Not a mock returning canned JSON: it reads NutriLens's own offline nutrition
 * reference — real per-100g values — and scales them to the portion the caller
 * asked for. Send it 150 g of chicken breast and it returns the nutrition of
 * 150 g of chicken breast.
 *
 * That matters for two reasons. The partner API is demonstrable end to end with
 * no key and no bill, and nobody integrating against it is ever shown a figure
 * that was made up. Its honest limitation is coverage: a food outside the table
 * falls back to a generic average and says so with a low confidence, which is
 * exactly what a real model should do too.
 *
 * The table itself lives in FoodNutritionTable, shared with Smart Plate, so a
 * portion of chicken means the same thing everywhere in the product.
 */
class FakeFoodEstimator implements FoodNutritionEstimator
{
    public function __construct(private readonly FoodNutritionTable $foods)
    {
    }

    public function providerName(): string
    {
        return 'fake';
    }

    public function modelName(): string
    {
        return 'nutrilens-fake-nutrition-table';
    }

    public function estimate(FoodQuery $query): array
    {
        $delay = (int) config('ai.providers.fake.delay_ms', 1200);

        if ($delay > 0 && ! app()->runningUnitTests()) {
            // A fraction of the vision delay: this is a much cheaper call.
            usleep((int) ($delay * 0.4) * 1000);
        }

        $items = [];
        $confidences = [];
        $unmatched = [];

        foreach ($query->items as $item) {
            $matched = $this->foods->lookup($item['name']);
            $grams = $this->foods->grams(
                (float) $item['portion_amount'],
                (string) $item['portion_unit'],
            );
            $per100 = $matched['macros'];
            $scale = $grams / 100;

            if (! $matched['exact']) {
                $unmatched[] = $item['name'];
            }

            $confidence = $this->confidenceFor($matched['exact'], (string) $item['portion_unit']);
            $confidences[] = $confidence;

            $items[] = [
                'name' => $item['name'],
                'portion_amount' => (float) $item['portion_amount'],
                'portion_unit' => (string) $item['portion_unit'],
                'calories' => round($per100[0] * $scale),
                'protein' => round($per100[1] * $scale, 1),
                'carbs' => round($per100[2] * $scale, 1),
                'fat' => round($per100[3] * $scale, 1),
                'confidence' => $confidence,
            ];
        }

        return [
            'meal_name' => $query->mealName ?? $this->nameFor($query),
            // The weakest item sets the tone, exactly as the prompt asks a real
            // model to do.
            'confidence' => $confidences === [] ? 0.0 : round(min($confidences), 3),
            'notes' => $this->notesFor($unmatched),
            'items' => $items,
        ];
    }

    private function confidenceFor(bool $exact, string $unit): float
    {
        if (! $exact) {
            return 0.32;
        }

        // A weight or volume is a real measurement; "1 serving" is an assumption
        // about how big a serving is.
        return in_array(Str::lower($unit), ['g', 'ml', 'oz', 'fl oz'], true) ? 0.86 : 0.68;
    }

    private function nameFor(FoodQuery $query): string
    {
        $first = $query->items[0]['name'] ?? 'Meal';
        $count = $query->count();

        if ($count === 1) {
            return Str::limit(Str::title($first), 60, '');
        }

        return Str::limit(Str::title($first), 40, '').' & more';
    }

    /** @param list<string> $unmatched */
    private function notesFor(array $unmatched): string
    {
        if ($unmatched === []) {
            return '';
        }

        $names = implode(', ', array_slice($unmatched, 0, 3));

        return count($unmatched) === 1
            ? "\"{$names}\" is not a food this estimator recognises, so a generic average was used."
            : "Some foods ({$names}) are not recognised, so generic averages were used.";
    }
}
