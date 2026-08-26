<?php

namespace App\Services\Nutrition;

use App\Models\Meal;
use App\Models\User;
use App\Services\AI\CoachContextService;
use App\Services\Nutrition\Data\PlateMeal;
use App\Services\Nutrition\Data\PlateScore;

/**
 * NutriLens Smart Plate — "Understand your meal. Optimize your day."
 *
 * Analyses a meal that has **not been saved yet** against the day the user is
 * actually having, and proposes concrete ways to make it fit better.
 *
 * The order of operations is the point of this class:
 *
 *  1. Read the user's real targets and what they have already eaten today,
 *     through CoachContextService — the same aggregation the dashboard and the
 *     AI Coach use, so Smart Plate cannot quote a figure the rest of the app
 *     disagrees with.
 *  2. Score the plate deterministically (MealFitScore).
 *  3. Turn the score's working into statuses and prose.
 *  4. Ask PlateOptimizer for the three optimizations, each simulated and
 *     re-scored.
 *
 * No AI provider is called, and nothing is written to the database. The whole
 * analysis is arithmetic over data we already have, which is why it can run on
 * every edit of an unsaved meal without costing anything.
 */
class SmartPlateService
{
    public function __construct(
        private readonly CoachContextService $context,
        private readonly MealFitScore $scorer,
        private readonly PlateOptimizer $optimizer,
    ) {
    }

    /**
     * @param  Meal|null  $editing  The saved meal being edited, if any — its
     *                              macros are already in today's totals and
     *                              must not be counted twice.
     * @return array<string, mixed>
     */
    public function analyse(User $user, PlateMeal $meal, ?Meal $editing = null): array
    {
        $progress = $this->context->todayProgress($user);
        $consumed = $this->consumedExcluding($progress, $editing);

        $day = [
            'date' => $progress['date'],
            'goal' => $user->activeNutritionGoal?->goal_type->label(),
            'targets' => $progress['targets'],
            'consumed' => $consumed,
            'remaining' => null,
            'remaining_after_meal' => null,
            // A first meal is analysed against the whole day, which is worth
            // saying rather than leaving the user to infer.
            'is_first_meal_today' => $this->mealsAlreadyToday($progress, $editing) === 0,
            'meals_logged_today' => $this->mealsAlreadyToday($progress, $editing),
        ];

        $totals = $meal->totals();

        if ($meal->isEmpty()) {
            return [
                'status' => 'empty_meal',
                'message' => 'Add a food item with some nutrition in it and Smart Plate can '
                    .'tell you how the meal fits your day.',
                'meal' => $totals,
                'day' => $day,
            ] + $this->blank();
        }

        if ($progress['targets'] === null) {
            return [
                'status' => 'no_goals',
                'message' => 'Set your nutrition goals to unlock personalized meal optimization.',
                'meal' => $totals,
                'day' => $day,
            ] + $this->blank();
        }

        $targets = $progress['targets'];
        $remaining = $this->remaining($targets, $consumed);

        $day['remaining'] = $remaining;
        $day['remaining_after_meal'] = $this->difference($remaining, $totals);

        $score = $this->scorer->evaluate($totals, $targets, $remaining);

        return [
            'status' => 'ok',
            'message' => null,
            'meal' => $totals,
            'day' => $day,
            'meal_fit_score' => $score->score,
            'rating' => $score->rating(),
            'rating_label' => $score->ratingLabel(),
            'summary' => $this->summary($score, $day),
            'breakdown' => $this->breakdown($score),
            'optimizations' => $this->optimizer->optimize($meal, $targets, $remaining, $score),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* The day                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Today's totals with the meal under edit taken back out.
     *
     * Without this, re-analysing a saved meal would compare it against a day
     * that already contains it, and every edit would look like a double
     * helping.
     *
     * @param  array<string, mixed>  $progress
     * @return array{calories:int, protein:float, carbs:float, fat:float}
     */
    private function consumedExcluding(array $progress, ?Meal $editing): array
    {
        $consumed = $progress['consumed'];

        if ($editing === null) {
            return $consumed;
        }

        $sameDay = $editing->consumed_on?->toDateString() === $progress['date'];

        if (! $sameDay) {
            return $consumed;
        }

        return [
            'calories' => max(0, $consumed['calories'] - $editing->total_calories),
            'protein' => round(max(0.0, $consumed['protein'] - (float) $editing->total_protein), 1),
            'carbs' => round(max(0.0, $consumed['carbs'] - (float) $editing->total_carbs), 1),
            'fat' => round(max(0.0, $consumed['fat'] - (float) $editing->total_fat), 1),
        ];
    }

    /** @param array<string, mixed> $progress */
    private function mealsAlreadyToday(array $progress, ?Meal $editing): int
    {
        $count = $progress['meals']->count();

        if ($editing !== null && $editing->consumed_on?->toDateString() === $progress['date']) {
            $count = max(0, $count - 1);
        }

        return $count;
    }

    /**
     * @param  array{calories:int, protein:int, carbs:int, fat:int}  $targets
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $consumed
     * @return array{calories:int, protein:float, carbs:float, fat:float}
     */
    private function remaining(array $targets, array $consumed): array
    {
        return $this->difference([
            'calories' => $targets['calories'],
            'protein' => (float) $targets['protein'],
            'carbs' => (float) $targets['carbs'],
            'fat' => (float) $targets['fat'],
        ], $consumed);
    }

    /**
     * @param  array<string, int|float>  $from
     * @param  array<string, int|float>  $subtract
     * @return array{calories:int, protein:float, carbs:float, fat:float}
     */
    private function difference(array $from, array $subtract): array
    {
        return [
            'calories' => (int) round($from['calories'] - $subtract['calories']),
            'protein' => round($from['protein'] - $subtract['protein'], 1),
            'carbs' => round($from['carbs'] - $subtract['carbs'], 1),
            'fat' => round($from['fat'] - $subtract['fat'], 1),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Prose                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * One line about the whole plate, led by whichever component is doing the
     * most to the score. Every figure in it comes from the score's own working.
     *
     * @param  array<string, mixed>  $day
     */
    private function summary(PlateScore $score, array $day): string
    {
        $protein = $score->component('protein');
        $calories = $score->component('calories');
        $weakest = $score->weakest();

        $opening = $day['is_first_meal_today']
            ? 'This is your first meal today, so Smart Plate is comparing it against your full daily targets. '
            : '';

        // Nothing is meaningfully wrong.
        if ($weakest === null || $score->score >= 9.0) {
            return $opening.($protein['target_already_met']
                ? 'A comfortable fit — your protein target for today is already met and this meal stays inside what is left.'
                : sprintf(
                    'Excellent fit for what today still needs: %sg of protein against the %sg a meal this size should carry, and inside your remaining calories.',
                    $this->grams($protein['meal']),
                    $this->grams($protein['expected']),
                ));
        }

        return $opening.match ($weakest) {
            'protein' => sprintf(
                'Good overall, but at %sg of protein this meal leaves you likely to finish the day about %sg below your target.',
                $this->grams($protein['meal']),
                $this->grams(max(0, $protein['remaining_after'])),
            ),
            'calories' => sprintf(
                'This meal is %s kcal more than the %s kcal you have left today.',
                number_format($calories['over_by']),
                number_format(max(0, $calories['headroom'])),
            ),
            'carbs', 'fat' => sprintf(
                'Solid on the whole, though %s comes in %sg over what was left for today.',
                $weakest === 'carbs' ? 'carbohydrate' : 'fat',
                $this->grams($score->component($weakest)['over_by']),
            ),
            default => 'This meal broadly fits what is left of today.',
        };
    }

    /**
     * The per-macro breakdown.
     *
     * Statuses are derived from the component scores rather than from a second
     * set of thresholds, so the four rows always add up to the number above
     * them. Each carries an icon-independent text label, because colour alone
     * is not an accessible way to say "this needs attention".
     *
     * @return array<string, array<string, mixed>>
     */
    private function breakdown(PlateScore $score): array
    {
        return [
            'calories' => $this->calorieRow($score),
            'protein' => $this->proteinRow($score),
            'carbs' => $this->macroRow($score, 'carbs', 'Carbohydrate'),
            'fat' => $this->macroRow($score, 'fat', 'Fat'),
        ];
    }

    /** @return array<string, mixed> */
    private function calorieRow(PlateScore $score): array
    {
        $c = $score->component('calories');
        $over = (float) $c['over_by'];
        $share = $c['share_of_headroom'];

        [$status, $message] = match (true) {
            $over > 0 => [
                $this->overshootStatus($c['score']),
                sprintf(
                    'This meal is %s kcal past the %s kcal left in today\'s budget.',
                    number_format($over),
                    number_format($c['headroom']),
                ),
            ],
            $share !== null && $share >= 0.9 => [
                'on_track',
                sprintf(
                    'This uses almost all of the %s kcal you had left today.',
                    number_format($c['headroom']),
                ),
            ],
            default => [
                'good',
                sprintf(
                    'Fits comfortably: %s kcal of the %s kcal you had left.',
                    number_format($c['meal']),
                    number_format($c['headroom']),
                ),
            ],
        };

        return ['status' => $status, 'label' => $this->label($status), 'message' => $message];
    }

    /** @return array<string, mixed> */
    private function proteinRow(PlateScore $score): array
    {
        $p = $score->component('protein');

        if ($p['target_already_met']) {
            return [
                'status' => 'excellent',
                'label' => $this->label('excellent'),
                'message' => 'Your protein target for today is already met.',
            ];
        }

        $covered = (float) $p['covered'];

        $status = match (true) {
            $covered >= 0.95 => 'excellent',
            $covered >= 0.8 => 'good',
            $covered >= 0.65 => 'on_track',
            $covered >= 0.4 => 'low',
            default => 'needs_attention',
        };

        return [
            'status' => $status,
            'label' => $this->label($status),
            'message' => $covered >= 0.95
                ? sprintf(
                    '%sg of protein — this closes the gap a meal this size should.',
                    $this->grams($p['meal']),
                )
                : sprintf(
                    '%sg of protein against the %sg a meal this size should carry. %sg still to go today.',
                    $this->grams($p['meal']),
                    $this->grams($p['expected']),
                    $this->grams(max(0, $p['remaining_after'])),
                ),
        ];
    }

    /** @return array<string, mixed> */
    private function macroRow(PlateScore $score, string $macro, string $noun): array
    {
        $c = $score->component($macro);
        $over = (float) $c['over_by'];
        $share = $c['share_of_headroom'];

        [$status, $message] = match (true) {
            $over > 0 => [
                $this->overshootStatus($c['score']),
                sprintf(
                    '%sg over the %sg of %s left for today.',
                    $this->grams($over),
                    $this->grams($c['headroom']),
                    lcfirst($noun),
                ),
            ],
            $share !== null && $share >= 0.9 => [
                'on_track',
                sprintf(
                    'Uses nearly all of the %sg you had left.',
                    $this->grams($c['headroom']),
                ),
            ],
            default => [
                'good',
                sprintf(
                    '%sg of the %sg you had left — comfortably within range.',
                    $this->grams($c['meal']),
                    $this->grams($c['headroom']),
                ),
            ],
        };

        return ['status' => $status, 'label' => $this->label($status), 'message' => $message];
    }

    private function overshootStatus(float $componentScore): string
    {
        return $componentScore >= 6.5 ? 'high' : 'needs_attention';
    }

    private function label(string $status): string
    {
        return match ($status) {
            'excellent' => 'Excellent',
            'good' => 'Good',
            'on_track' => 'On track',
            'high' => 'Slightly high',
            'low' => 'Low',
            default => 'Needs attention',
        };
    }

    /**
     * The fields a non-`ok` analysis still has to carry, so the client never has
     * to guess whether a key exists.
     *
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        return [
            'meal_fit_score' => null,
            'rating' => null,
            'rating_label' => null,
            'summary' => null,
            'breakdown' => null,
            'optimizations' => [],
        ];
    }

    private function grams(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    }
}
