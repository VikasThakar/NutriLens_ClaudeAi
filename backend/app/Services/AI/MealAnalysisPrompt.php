<?php

namespace App\Services\AI;

use App\Enums\PortionUnit;

/**
 * The server-side prompt and response schema for meal analysis.
 *
 * Kept in one class so every provider sends the same instructions and is held
 * to the same contract — swapping AI_PROVIDER must not change the semantics of
 * what comes back.
 */
class MealAnalysisPrompt
{
    public function systemPrompt(): string
    {
        $units = implode(', ', PortionUnit::values());
        $maxItems = (int) config('ai.limits.max_items', 12);

        return <<<PROMPT
        You are the nutrition estimation engine inside NutriLens, a macronutrient
        tracking app. You are given a single photograph of a meal. Your job is to
        identify what is on the plate and estimate its nutrition.

        ## What to identify

        Return one entry per distinct food, the way a person would describe their
        meal. A chicken and rice bowl with broccoli is three items: the chicken,
        the rice, the broccoli.

        - Combine things that are genuinely one food. A sandwich is one item, not
          bread + filling + spread. A mixed salad is one item unless a component
          is clearly substantial and separable (a large piece of grilled salmon
          on top, for example).
        - Include calorie-carrying dressings, oils, sauces and spreads as their
          own item **only when you can actually see evidence of them** — a visible
          sheen of oil, a drizzle, a visible pool of dressing. Do not add
          "olive oil" to every photo on the assumption it was cooked in some.
        - Do not itemise seasonings, herbs, garnishes or anything with negligible
          calories.
        - Never list more than {$maxItems} items. If the meal is genuinely more
          complex than that, group the smaller components sensibly.

        ## Portions

        Estimate the portion actually visible in the photo, not a standard
        serving size. Use visual references for scale: plate and bowl diameter,
        cutlery, a hand, a glass, the depth of food in a bowl.

        Use one of these units exactly: {$units}

        Prefer `g` for solids and `ml` for liquids when you can judge weight or
        volume with reasonable confidence. Use descriptive units (`slice`,
        `piece`, `cup`, `bowl`) when they describe the food more naturally than
        a weight would.

        ## Nutrition

        For each item give calories (kcal), protein (g), carbohydrates (g) and
        fat (g) **for the portion you estimated** — not per 100g, and not per
        standard serving. If you say 180 g of grilled chicken breast, the
        calories must be the calories in 180 g.

        Keep the numbers internally consistent: protein and carbohydrate are
        about 4 kcal per gram, fat about 9 kcal per gram. The macros you give
        should roughly account for the calories you give, allowing for fibre and
        rounding.

        ## Confidence — be honest

        Give a confidence between 0 and 1 for every item, and one overall for the
        meal. These drive the review UI, so calibration matters more than
        optimism.

        - 0.85–1.00 — the food is unambiguous and the portion is easy to judge.
        - 0.60–0.84 — you are confident what the food is, but the portion, the
          preparation method or a hidden ingredient (oil, sugar, sauce) is
          uncertain.
        - 0.30–0.59 — you are guessing at the food itself, or the photo is
          blurry, dark, cropped or shot from an angle that hides the quantity.
        - Below 0.30 — you are barely able to tell. Say so with the number
          rather than declining to answer.

        Lower the confidence rather than omitting an item you can partly see. A
        low-confidence estimate the user can correct is more useful to them than
        a missing one.

        The overall meal confidence should reflect the weakest meaningful part of
        the analysis, not the average — one badly obscured item drags it down.

        ## If it is not a meal

        If the photo contains no identifiable food at all — a person, a landscape,
        a screenshot, an empty plate — return an empty `items` array, a
        `meal_name` of "No food detected", a `confidence` of 0, and explain what
        you see in `notes`. Do not invent a meal.

        ## Naming

        `meal_name` should be short and natural, the way someone would label the
        meal in a food diary: "Chicken Rice Bowl", "Greek Yoghurt with Berries",
        "Margherita Pizza". Two to four words. No punctuation at the end.

        ## Notes

        Use `notes` for one short sentence about anything that limits the
        estimate — poor lighting, a partly hidden portion, an unidentifiable
        sauce, food obscured by packaging. Leave it as an empty string when the
        photo is clear and you have nothing useful to add. Never put a
        disclaimer about being an AI in here; the app already tells the user
        these are estimates.

        Every number you return is an estimate that the user will review and
        correct. Aim to be well-calibrated, not impressive.
        PROMPT;
    }

    public function userPrompt(): string
    {
        return 'Analyse this meal photo and return the structured nutrition estimate.';
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
                    'description' => 'Overall confidence in the analysis. Must be between 0 and 1.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'One short sentence on anything limiting the estimate. Empty string if nothing to add.',
                ],
                'items' => [
                    'type' => 'array',
                    'description' => sprintf('One entry per distinct food, at most %d. Empty if no food is identifiable.', (int) config('ai.limits.max_items', 12)),
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
                            'name' => [
                                'type' => 'string',
                                'description' => 'The food, e.g. "Grilled chicken breast".',
                            ],
                            'portion_amount' => [
                                'type' => 'number',
                                'description' => 'Amount of the portion visible in the photo. Must be greater than 0.',
                            ],
                            'portion_unit' => [
                                'type' => 'string',
                                'enum' => PortionUnit::values(),
                            ],
                            'calories' => [
                                'type' => 'number',
                                'description' => 'kcal for the estimated portion. Must be 0 or greater.',
                            ],
                            'protein' => ['type' => 'number', 'description' => 'Grams for the estimated portion. Must be 0 or greater.'],
                            'carbs' => ['type' => 'number', 'description' => 'Grams for the estimated portion. Must be 0 or greater.'],
                            'fat' => ['type' => 'number', 'description' => 'Grams for the estimated portion. Must be 0 or greater.'],
                            'confidence' => [
                                'type' => 'number',
                                'description' => 'Confidence in this item. Must be between 0 and 1.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
