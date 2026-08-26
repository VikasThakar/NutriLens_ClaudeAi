<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\MealVisionAnalyzer;
use App\Services\AI\Data\PreparedImage;

/**
 * Offline driver — AI_PROVIDER=fake.
 *
 * Returns a deterministic multi-item analysis derived from the image bytes, so
 * the same photo always yields the same result but different photos differ. It
 * exists so the whole capture → analyse → review → save flow (including the
 * low-confidence review path) can be built and tested without a key, a network
 * call or a bill.
 */
class FakeMealAnalyzer implements MealVisionAnalyzer
{
    /**
     * Plausible plates. The last one deliberately carries low confidence so the
     * "needs review" UI is reachable in local development.
     *
     * @var list<array<string, mixed>>
     */
    private const PLATES = [
        [
            'meal_name' => 'Chicken Rice Bowl',
            'confidence' => 0.88,
            'notes' => '',
            'items' => [
                ['name' => 'Grilled chicken breast', 'portion_amount' => 165, 'portion_unit' => 'g', 'calories' => 272, 'protein' => 50.5, 'carbs' => 0, 'fat' => 6.0, 'confidence' => 0.93],
                ['name' => 'Brown rice', 'portion_amount' => 1, 'portion_unit' => 'cup', 'calories' => 216, 'protein' => 5.0, 'carbs' => 45.0, 'fat' => 1.8, 'confidence' => 0.89],
                ['name' => 'Steamed broccoli', 'portion_amount' => 90, 'portion_unit' => 'g', 'calories' => 31, 'protein' => 2.6, 'carbs' => 6.0, 'fat' => 0.3, 'confidence' => 0.91],
                ['name' => 'Olive oil drizzle', 'portion_amount' => 1, 'portion_unit' => 'tbsp', 'calories' => 119, 'protein' => 0, 'carbs' => 0, 'fat' => 13.5, 'confidence' => 0.62],
            ],
        ],
        [
            'meal_name' => 'Greek Yoghurt with Berries',
            'confidence' => 0.84,
            'notes' => '',
            'items' => [
                ['name' => 'Greek yoghurt, 2%', 'portion_amount' => 200, 'portion_unit' => 'g', 'calories' => 146, 'protein' => 20.0, 'carbs' => 7.6, 'fat' => 4.0, 'confidence' => 0.87],
                ['name' => 'Mixed berries', 'portion_amount' => 80, 'portion_unit' => 'g', 'calories' => 45, 'protein' => 0.7, 'carbs' => 10.4, 'fat' => 0.3, 'confidence' => 0.9],
                ['name' => 'Honey', 'portion_amount' => 1, 'portion_unit' => 'tsp', 'calories' => 21, 'protein' => 0, 'carbs' => 5.8, 'fat' => 0, 'confidence' => 0.55],
            ],
        ],
        [
            'meal_name' => 'Beef Stir Fry',
            'confidence' => 0.71,
            'notes' => 'The sauce is hard to identify, so its sugar and oil content is uncertain.',
            'items' => [
                ['name' => 'Beef strips', 'portion_amount' => 140, 'portion_unit' => 'g', 'calories' => 296, 'protein' => 36.0, 'carbs' => 0, 'fat' => 16.0, 'confidence' => 0.82],
                ['name' => 'Egg noodles', 'portion_amount' => 180, 'portion_unit' => 'g', 'calories' => 250, 'protein' => 8.2, 'carbs' => 47.0, 'fat' => 2.9, 'confidence' => 0.78],
                ['name' => 'Mixed peppers and onion', 'portion_amount' => 110, 'portion_unit' => 'g', 'calories' => 38, 'protein' => 1.2, 'carbs' => 8.1, 'fat' => 0.3, 'confidence' => 0.85],
                ['name' => 'Stir fry sauce', 'portion_amount' => 2, 'portion_unit' => 'tbsp', 'calories' => 84, 'protein' => 1.0, 'carbs' => 12.0, 'fat' => 3.4, 'confidence' => 0.41],
            ],
        ],
        [
            'meal_name' => 'Mixed Plate',
            'confidence' => 0.38,
            'notes' => 'The photo is dark and shot at a steep angle, so portions are rough.',
            'items' => [
                ['name' => 'Breaded chicken', 'portion_amount' => 120, 'portion_unit' => 'g', 'calories' => 290, 'protein' => 22.0, 'carbs' => 14.0, 'fat' => 16.0, 'confidence' => 0.44],
                ['name' => 'Potato wedges', 'portion_amount' => 150, 'portion_unit' => 'g', 'calories' => 220, 'protein' => 3.4, 'carbs' => 33.0, 'fat' => 8.2, 'confidence' => 0.36],
                ['name' => 'Creamy dip', 'portion_amount' => 2, 'portion_unit' => 'tbsp', 'calories' => 110, 'protein' => 0.6, 'carbs' => 2.0, 'fat' => 11.4, 'confidence' => 0.27],
            ],
        ],
    ];

    public function providerName(): string
    {
        return 'fake';
    }

    public function modelName(): string
    {
        return 'nutrilens-fake-vision';
    }

    public function analyze(PreparedImage $image): array
    {
        $delay = (int) config('ai.providers.fake.delay_ms', 1200);

        if ($delay > 0 && ! app()->runningUnitTests()) {
            usleep($delay * 1000);
        }

        // Deterministic per image: the same photo always analyses the same way.
        $index = crc32($image->binary) % count(self::PLATES);

        return self::PLATES[$index];
    }
}
