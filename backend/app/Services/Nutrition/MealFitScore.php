<?php

namespace App\Services\Nutrition;

use App\Services\Nutrition\Data\PlateScore;

/**
 * The Smart Plate Meal Fit Score: 0–10, deterministic, explainable.
 *
 * The question it answers is not "is this a healthy meal" — that is not
 * something a macro total can tell you. It is narrower and answerable: **given
 * what this user has left of today's targets, how well does this meal use it?**
 *
 * Four components, each scored 0–10 and then weighted:
 *
 *  - **Calories (30%)** — penalised *only* for overshooting what is left. A
 *    small meal is not a bad meal; it simply does not do much for you, which is
 *    what the protein component is for.
 *  - **Protein (35%)** — the heaviest weight, because protein is the macro
 *    people miss. The expectation is self-calibrating: a meal that uses 40% of
 *    the day's remaining energy is expected to deliver 40% of the remaining
 *    protein. That is why a light breakfast is not punished for being light,
 *    while a meal that eats the whole remaining calorie budget *is* expected to
 *    close the protein gap — because after it, there is no room left to.
 *  - **Carbs (17.5%)** and **Fat (17.5%)** — penalised for overshooting what is
 *    left, on the same curve as calories but scaled to each macro's own target.
 *
 * Every threshold below is a named constant, so the same data always produces
 * the same score and the reason for it can be read off the components.
 */
class MealFitScore
{
    /** @var array<string, float> */
    public const WEIGHTS = [
        'calories' => 0.30,
        'protein' => 0.35,
        'carbs' => 0.175,
        'fat' => 0.175,
    ];

    /**
     * Overshooting the remaining calories by this fraction of the *daily*
     * target scores zero. The daily target is the denominator rather than the
     * remaining amount, because "remaining" can be zero or negative — and being
     * 200 kcal over means the same thing whether it happened at breakfast or at
     * dinner.
     */
    private const CALORIE_ZERO_POINT = 0.30;

    /** The same idea for carbohydrates and fat, which tolerate a little more. */
    private const MACRO_ZERO_POINT = 0.35;

    /**
     * The smallest share of the day's remaining energy a meal is treated as
     * using. Without a floor, a 50 kcal snack would be expected to deliver
     * almost no protein and would score a perfect 10 for it.
     */
    private const MIN_ENERGY_SHARE = 0.15;

    /** Below this many grams, a "remaining protein gap" is not worth chasing. */
    private const PROTEIN_GAP_FLOOR = 1.0;

    /**
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $meal
     * @param  array{calories:int, protein:int, carbs:int, fat:int}  $targets
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $remaining  Before this meal
     */
    public function evaluate(array $meal, array $targets, array $remaining): PlateScore
    {
        $components = [
            'calories' => $this->overshootComponent(
                $meal['calories'],
                $remaining['calories'],
                $targets['calories'],
                self::CALORIE_ZERO_POINT,
            ),
            'protein' => $this->proteinComponent($meal, $remaining),
            'carbs' => $this->overshootComponent(
                $meal['carbs'],
                $remaining['carbs'],
                $targets['carbs'],
                self::MACRO_ZERO_POINT,
            ),
            'fat' => $this->overshootComponent(
                $meal['fat'],
                $remaining['fat'],
                $targets['fat'],
                self::MACRO_ZERO_POINT,
            ),
        ];

        $total = 0.0;

        foreach (self::WEIGHTS as $macro => $weight) {
            $total += $components[$macro]['score'] * $weight;
        }

        return new PlateScore(
            score: round(max(0.0, min(10.0, $total)), 1),
            components: $components,
        );
    }

    /**
     * Calories, carbohydrates and fat all share one shape: full marks while the
     * meal stays inside what is left, then a straight decline as it goes past.
     *
     * @return array<string, mixed>
     */
    private function overshootComponent(
        float $mealValue,
        float $remainingValue,
        float $target,
        float $zeroPoint,
    ): array {
        $headroom = max(0.0, $remainingValue);
        $over = max(0.0, $mealValue - $headroom);
        $overShare = $target > 0 ? $over / $target : 0.0;

        $score = $zeroPoint > 0
            ? 10.0 * max(0.0, min(1.0, 1.0 - ($overShare / $zeroPoint)))
            : 10.0;

        return [
            'score' => round($score, 2),
            'meal' => round($mealValue, 1),
            'remaining_before' => round($remainingValue, 1),
            'headroom' => round($headroom, 1),
            'over_by' => round($over, 1),
            // What is left of this macro once the meal is counted. Negative
            // means the target has been passed.
            'remaining_after' => round($remainingValue - $mealValue, 1),
            'share_of_headroom' => $headroom > 0 ? round($mealValue / $headroom, 3) : null,
        ];
    }

    /**
     * Protein is scored on contribution rather than overshoot: going *over* a
     * protein target is not a problem worth flagging, and falling short is the
     * single most common way a day misses its goals.
     *
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $meal
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $remaining
     * @return array<string, mixed>
     */
    private function proteinComponent(array $meal, array $remaining): array
    {
        $gap = max(0.0, $remaining['protein']);
        $energyLeft = max(0.0, (float) $remaining['calories']);
        $mealCalories = max(0.0, (float) $meal['calories']);

        // The share of the day's remaining energy this meal accounts for. When
        // the meal is larger than what is left, that share is 1: there will be
        // no room afterwards, so this meal has to carry the protein.
        $share = $mealCalories > 0
            ? min(1.0, $mealCalories / max($mealCalories, $energyLeft))
            : self::MIN_ENERGY_SHARE;
        $share = max(self::MIN_ENERGY_SHARE, $share);

        $expected = $gap * $share;

        if ($gap < self::PROTEIN_GAP_FLOOR || $expected < self::PROTEIN_GAP_FLOOR) {
            // Nothing left to chase — the target is already met, so no meal can
            // be faulted for its protein.
            return [
                'score' => 10.0,
                'meal' => round($meal['protein'], 1),
                'remaining_before' => round($remaining['protein'], 1),
                'remaining_after' => round($remaining['protein'] - $meal['protein'], 1),
                'gap' => round($gap, 1),
                'expected' => round($expected, 1),
                'covered' => 1.0,
                'target_already_met' => $gap < self::PROTEIN_GAP_FLOOR,
            ];
        }

        $covered = min(1.0, $meal['protein'] / $expected);

        return [
            'score' => round(10.0 * $covered, 2),
            'meal' => round($meal['protein'], 1),
            'remaining_before' => round($remaining['protein'], 1),
            'remaining_after' => round($remaining['protein'] - $meal['protein'], 1),
            'gap' => round($gap, 1),
            // How much protein a meal this size should be carrying.
            'expected' => round($expected, 1),
            'covered' => round($covered, 3),
            'target_already_met' => false,
        ];
    }
}
