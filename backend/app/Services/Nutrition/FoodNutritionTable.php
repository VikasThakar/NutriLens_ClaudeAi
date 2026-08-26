<?php

namespace App\Services\Nutrition;

use Illuminate\Support\Str;

/**
 * NutriLens's offline nutrition reference: real per-100 g values for common
 * foods, plus what a descriptive unit ("1 cup", "1 slice") weighs.
 *
 * This used to live as private constants inside FakeFoodEstimator. It is shared
 * now because Smart Plate needs the same numbers to size a suggestion — "add
 * 100 g of grilled chicken" has to mean the same 165 kcal everywhere in the
 * product, and two copies of a nutrition table are two tables that will
 * eventually disagree.
 *
 * It is a reference table, not a database: coverage is the honest limitation,
 * which is why an unmatched food falls back to a deliberately unremarkable
 * average and says so.
 */
class FoodNutritionTable
{
    /**
     * Per 100 g (or 100 ml): [kcal, protein, carbs, fat].
     *
     * Keys are matched as substrings against the lower-cased food name, longest
     * first, so "chicken breast" wins over "chicken".
     *
     * @var array<string, array{0:float, 1:float, 2:float, 3:float}>
     */
    public const TABLE = [
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
        'cottage cheese' => [98, 11.1, 3.4, 4.3],
        'paneer' => [296, 18.9, 3.6, 22.8],
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
     * unremarkable, and paired with a low confidence wherever it is used.
     *
     * @var array{0:float, 1:float, 2:float, 3:float}
     */
    public const FALLBACK = [180, 8.0, 20.0, 6.0];

    /**
     * Grams (or millilitres) a descriptive unit stands for.
     *
     * @var array<string, float>
     */
    public const UNIT_GRAMS = [
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

    /**
     * Look a food up by name.
     *
     * @return array{macros: array{0:float, 1:float, 2:float, 3:float}, exact: bool}
     */
    public function lookup(string $name): array
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

    /**
     * The per-100 g figures for an exact table key.
     *
     * Used by Smart Plate, which names its own foods rather than matching free
     * text, so a missing key is a bug in that list rather than an unrecognised
     * food.
     *
     * @return array{0:float, 1:float, 2:float, 3:float}
     */
    public function perHundred(string $key): array
    {
        return self::TABLE[$key] ?? self::FALLBACK;
    }

    /** What a portion in the given unit weighs, in grams. */
    public function grams(float $amount, string $unit): float
    {
        $factor = self::UNIT_GRAMS[Str::lower(trim($unit))] ?? self::UNIT_GRAMS['serving'];

        return max(0.0, $amount * $factor);
    }

    /** Whether a unit has a known weight, so a portion can be reasoned about. */
    public function knowsUnit(string $unit): bool
    {
        return array_key_exists(Str::lower(trim($unit)), self::UNIT_GRAMS);
    }
}
