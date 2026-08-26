<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\FoodNutritionEstimator;
use App\Services\AI\Data\FoodQuery;
use Illuminate\Support\Str;

/**
 * Offline driver — AI_PROVIDER=fake.
 *
 * Not a mock returning canned JSON: this is a small keyword-matched nutrition
 * table with real per-100g values, scaled to the portion the caller asked for.
 * Send it 150 g of chicken breast and it returns the nutrition of 150 g of
 * chicken breast.
 *
 * That matters for two reasons. The partner API is demonstrable end to end with
 * no key and no bill, and nobody integrating against it is ever shown a figure
 * that was made up. Its honest limitation is coverage: a food outside the table
 * falls back to a generic average and says so with a low confidence, which is
 * exactly what a real model should do too.
 */
class FakeFoodEstimator implements FoodNutritionEstimator
{
    public function providerName(): string
    {
        return 'fake';
    }

    public function modelName(): string
    {
        return 'nutrilens-fake-nutrition-table';
    }

    /**
     * Per 100 g (or 100 ml): [kcal, protein, carbs, fat].
     *
     * Keys are matched as substrings against the lower-cased food name, longest
     * first, so "chicken breast" wins over "chicken".
     *
     * @var array<string, array{0:float, 1:float, 2:float, 3:float}>
     */
    private const TABLE = [
        'grilled chicken breast' => [165, 31.0, 0.0, 3.6],
        'chicken breast' => [165, 31.0, 0.0, 3.6],
        'chicken thigh' => [209, 26.0, 0.0, 10.9],
        'chicken' => [190, 27.0, 0.0, 8.1],
        'brown rice' => [123, 2.7, 25.6, 1.0],
        'white rice' => [130, 2.7, 28.2, 0.3],
        'rice' => [130, 2.7, 28.2, 0.3],
        'salmon' => [208, 20.4, 0.0, 13.4],
        'tuna' => [132, 28.0, 0.0, 1.3],
        'white fish' => [96, 20.5, 0.0, 1.2],
        'prawn' => [99, 24.0, 0.2, 0.3],
        'beef mince' => [217, 26.1, 0.0, 12.0],
        'steak' => [271, 25.4, 0.0, 18.7],
        'beef' => [250, 26.0, 0.0, 15.0],
        'pork' => [242, 27.3, 0.0, 14.0],
        'bacon' => [541, 37.0, 1.4, 42.0],
        'egg' => [155, 12.6, 1.1, 10.6],
        'tofu' => [76, 8.1, 1.9, 4.8],
        'lentil' => [116, 9.0, 20.1, 0.4],
        'chickpea' => [164, 8.9, 27.4, 2.6],
        'black bean' => [132, 8.9, 23.7, 0.5],
        'greek yoghurt' => [73, 10.0, 3.8, 2.0],
        'yoghurt' => [59, 3.5, 7.0, 1.6],
        'yogurt' => [59, 3.5, 7.0, 1.6],
        'cheddar' => [403, 24.9, 1.3, 33.1],
        'mozzarella' => [280, 27.5, 3.1, 17.1],
        'cheese' => [380, 24.0, 2.0, 30.0],
        'milk' => [50, 3.3, 4.8, 2.0],
        'butter' => [717, 0.9, 0.1, 81.1],
        'olive oil' => [884, 0.0, 0.0, 100.0],
        'wholemeal bread' => [247, 13.0, 41.0, 3.4],
        'bread' => [265, 9.0, 49.0, 3.2],
        'pasta' => [158, 5.8, 31.0, 0.9],
        'noodle' => [138, 4.5, 25.0, 2.1],
        'potato' => [87, 2.0, 20.1, 0.1],
        'sweet potato' => [90, 2.0, 20.7, 0.2],
        'oat' => [379, 13.2, 67.7, 6.5],
        'porridge' => [71, 2.5, 12.0, 1.5],
        'quinoa' => [120, 4.4, 21.3, 1.9],
        'broccoli' => [35, 2.4, 7.2, 0.4],
        'spinach' => [23, 2.9, 3.6, 0.4],
        'mixed vegetable' => [45, 2.4, 8.7, 0.3],
        'salad' => [20, 1.5, 3.6, 0.2],
        'tomato' => [18, 0.9, 3.9, 0.2],
        'avocado' => [160, 2.0, 8.5, 14.7],
        'banana' => [89, 1.1, 22.8, 0.3],
        'apple' => [52, 0.3, 13.8, 0.2],
        'berries' => [57, 0.7, 14.5, 0.3],
        'orange' => [47, 0.9, 11.8, 0.1],
        'almond' => [579, 21.2, 21.6, 49.9],
        'peanut butter' => [588, 25.1, 20.0, 50.4],
        'nuts' => [607, 20.0, 21.0, 54.0],
        'honey' => [304, 0.3, 82.4, 0.0],
        'chocolate' => [546, 4.9, 61.0, 31.3],
        'pizza' => [266, 11.0, 33.0, 10.0],
        'burger' => [295, 17.0, 24.0, 14.0],
        'sandwich' => [250, 12.0, 30.0, 9.0],
        'wrap' => [240, 11.0, 32.0, 8.0],
        'curry' => [150, 9.0, 12.0, 8.0],
        'stir fry' => [140, 11.0, 13.0, 5.0],
        'soup' => [60, 3.0, 7.0, 2.0],
        'orange juice' => [45, 0.7, 10.4, 0.2],
        'juice' => [46, 0.5, 11.0, 0.1],
        'beer' => [43, 0.5, 3.6, 0.0],
        'wine' => [83, 0.1, 2.6, 0.0],
        'coffee' => [2, 0.1, 0.0, 0.0],
        'tea' => [1, 0.0, 0.2, 0.0],
        'protein shake' => [83, 16.0, 3.0, 1.0],
        'protein bar' => [370, 30.0, 38.0, 10.0],
    ];

    /**
     * A food outside the table. Roughly a mixed cooked dish — deliberately
     * unremarkable, and paired with a low confidence.
     *
     * @var array{0:float, 1:float, 2:float, 3:float}
     */
    private const FALLBACK = [180, 8.0, 20.0, 6.0];

    /**
     * Grams (or millilitres) a descriptive unit stands for.
     *
     * @var array<string, float>
     */
    private const UNIT_GRAMS = [
        'g' => 1.0,
        'ml' => 1.0,
        'oz' => 28.35,
        'fl oz' => 29.57,
        'cup' => 240.0,
        'tbsp' => 15.0,
        'tsp' => 5.0,
        'slice' => 30.0,
        'piece' => 100.0,
        'serving' => 200.0,
        'bowl' => 350.0,
        'plate' => 450.0,
    ];

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
            $matched = $this->lookup($item['name']);
            $grams = $this->grams((float) $item['portion_amount'], (string) $item['portion_unit']);
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

    /**
     * @return array{macros: array{0:float, 1:float, 2:float, 3:float}, exact: bool}
     */
    private function lookup(string $name): array
    {
        $needle = Str::lower(trim($name));

        // Longest key first, so "chicken breast" is not shadowed by "chicken".
        $keys = array_keys(self::TABLE);
        usort($keys, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $key) {
            if (str_contains($needle, $key)) {
                return ['macros' => self::TABLE[$key], 'exact' => true];
            }
        }

        return ['macros' => self::FALLBACK, 'exact' => false];
    }

    private function grams(float $amount, string $unit): float
    {
        $factor = self::UNIT_GRAMS[Str::lower(trim($unit))] ?? self::UNIT_GRAMS['serving'];

        return max(0.0, $amount * $factor);
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
