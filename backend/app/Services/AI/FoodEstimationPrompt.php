<?php

namespace App\Services\AI;

use App\Enums\PortionUnit;
use App\Services\AI\Data\FoodQuery;

/**
 * The server-side prompt and response schema for structured food estimation —
 * the partner API's Option B, where the caller says what the food is instead of
 * sending a photograph.
 *
 * Kept in one class so every provider sends the same instructions and returns
 * the same shape, exactly as MealAnalysisPrompt does for vision.
 */
class FoodEstimationPrompt
{
    public function systemPrompt(): string
    {
        $units = implode(', ', PortionUnit::values());

        return <<<PROMPT
        You are the nutrition estimation engine behind the NutriLens API. A
        caller has told you what a meal contains and how much of each food. Your
        job is to return the nutrition for exactly those foods, at exactly those
        portions.

        ## The rules

        - Return **one entry per food you were given, in the same order**. Do not
          merge two foods, do not split one into components, and do not add a
          food that was not listed. If the caller lists three foods, you return
          three entries.
        - Give calories (kcal), protein (g), carbohydrates (g) and fat (g) **for
          the portion you were given** — not per 100 g and not per standard
          serving. If the request says 150 g of chicken breast, the numbers must
          be for 150 g.
        - Echo each food's `name` back. You may tidy capitalisation and
          normalise obvious spelling, but do not rename the food into something
          else.
        - Echo `portion_amount` and `portion_unit` back unchanged. Converting
          units is not your job; the caller chose them deliberately.

        ## Units

        The caller uses one of: {$units}

        A descriptive unit (`slice`, `piece`, `cup`, `serving`) means a typical
        example of that food at that measure — a slice of bread, a cup of cooked
        rice. Use a reasonable standard size for it.

        ## Keep the numbers coherent

        Protein and carbohydrate are about 4 kcal per gram, fat about 9. The
        macros you return should roughly account for the calories you return,
        allowing for fibre and rounding.

        ## Confidence — be honest

        Give a confidence between 0 and 1 per item, and one overall.

        - 0.85–1.00 — a common, well-characterised food at an unambiguous portion.
        - 0.60–0.84 — the food is clear but the preparation matters, or the unit
          is descriptive rather than a weight.
        - 0.30–0.59 — the name is vague ("salad", "curry"), a regional dish with
          wide variation, or a portion you can only guess at.
        - Below 0.30 — you cannot tell what the food is. Say so with the number
          rather than refusing.

        A brand name you do not recognise lowers confidence; it does not license
        you to invent a specific product's label data.

        ## meal_name

        A short, natural name for the meal as a whole — two to four words, no
        trailing punctuation. If the caller supplied one, keep it.

        ## notes

        One short sentence if something genuinely limits the estimate: a vague
        food name, an unusual unit for that food, an unrecognised brand. An empty
        string when there is nothing useful to say. Never a disclaimer about
        being an AI — the API already tells callers these are estimates.

        Every number you return is an estimate. Aim to be well-calibrated rather
        than confident.
        PROMPT;
    }

    public function userPrompt(FoodQuery $query): string
    {
        $json = json_encode(
            $query->toPayload(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $name = $query->mealName !== null
            ? "The caller named this meal \"{$query->mealName}\"."
            : 'The caller did not name this meal.';

        return <<<TEXT
        Estimate the nutrition for these {$query->count()} foods. {$name}

        {$json}

        Return one entry per food, in the same order, as JSON matching the schema.
        TEXT;
    }

    /**
     * JSON Schema the response must satisfy. Used natively by providers that
     * support structured outputs, and re-validated server-side either way.
     *
     * @return array<string, mixed>
     */
    public function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['meal_name', 'confidence', 'items', 'notes'],
            'properties' => [
                'meal_name' => [
                    'type' => 'string',
                    'description' => 'Short natural name for the meal, 2-4 words.',
                ],
                'confidence' => [
                    'type' => 'number',
                    'description' => 'Overall confidence in the estimate. Must be between 0 and 1.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'One short sentence on anything limiting the estimate. Empty string if nothing to add.',
                ],
                'items' => [
                    'type' => 'array',
                    'description' => 'One entry per food supplied, in the same order. Never empty.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'name',
                            'portion_amount',
                            'portion_unit',
                            'calories',
                            'protein',
                            'carbs',
                            'fat',
                            'confidence',
                        ],
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'The food, echoed back.'],
                            'portion_amount' => [
                                'type' => 'number',
                                'description' => 'Echoed back unchanged. Must be greater than 0.',
                            ],
                            'portion_unit' => [
                                'type' => 'string',
                                'enum' => PortionUnit::values(),
                                'description' => 'Echoed back unchanged.',
                            ],
                            'calories' => ['type' => 'number', 'description' => 'kcal for the given portion. Must be 0 or greater.'],
                            'protein' => ['type' => 'number', 'description' => 'Grams for the given portion. Must be 0 or greater.'],
                            'carbs' => ['type' => 'number', 'description' => 'Grams for the given portion. Must be 0 or greater.'],
                            'fat' => ['type' => 'number', 'description' => 'Grams for the given portion. Must be 0 or greater.'],
                            'confidence' => ['type' => 'number', 'description' => 'Confidence in this item. Must be between 0 and 1.'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
