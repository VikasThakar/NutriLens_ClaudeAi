<?php

namespace App\Services\AI;

use App\Models\Meal;
use App\Models\User;
use App\Services\AI\Data\CoachContext;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\StreakService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the nutrition context the AI Coach answers from.
 *
 * This is the class that makes the coach a NutriLens feature rather than a
 * chatbot: it reads the caller's own rows out of MySQL and turns them into the
 * small, pre-computed CoachContext the model is given.
 *
 * Three rules govern what it does:
 *
 *  1. **Reuse, do not reimplement.** The seven-day figures come from
 *     AnalyticsService and the streak from StreakService — the same code the
 *     Analytics and Today screens use, so the coach can never quote a number
 *     that disagrees with the rest of the app.
 *  2. **Compute the arithmetic here.** Remaining macros and percentages of
 *     target are worked out in PHP, not asked of the model.
 *  3. **Send the minimum.** Identity, credentials, ids, photos and body
 *     metrics are never part of the context. See CoachContext.
 *
 * A fresh context is built for every message, so a conversation reopened
 * tomorrow is answered against tomorrow's numbers.
 */
class CoachContextService
{
    /** Meals from before today included as background. */
    private const RECENT_MEALS = 8;

    /** Days of history summarised for the coach. */
    private const TREND_DAYS = 7;

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly StreakService $streaks,
    ) {
    }

    /**
     * The day's targets, totals and what is left of them.
     *
     * Public because the meal tip needs exactly this and nothing else — there
     * is no reason for a tip after saving one meal to pay for a streak
     * calculation and a seven-day aggregation.
     *
     * @return array{
     *     date:string,
     *     meals:Collection<int, Meal>,
     *     targets:?array{calories:int, protein:int, carbs:int, fat:int},
     *     consumed:array{calories:int, protein:float, carbs:float, fat:float},
     *     remaining:?array{calories:int, protein:float, carbs:float, fat:float},
     *     percent_of_target:?array{calories:int, protein:int, carbs:int, fat:int}
     * }
     */
    public function todayProgress(User $user): array
    {
        $today = $user->today();
        $goal = $user->activeNutritionGoal;

        $meals = $user->meals()
            ->logged()
            ->onDate($today->toDateString())
            ->orderBy('consumed_at')
            ->get();

        $consumed = $this->totals($meals);

        $targets = $goal ? [
            'calories' => $goal->calorie_target,
            'protein' => $goal->protein_target,
            'carbs' => $goal->carb_target,
            'fat' => $goal->fat_target,
        ] : null;

        return [
            'date' => $today->toDateString(),
            'meals' => $meals,
            'targets' => $targets,
            'consumed' => $consumed,
            'remaining' => $targets ? $this->remaining($targets, $consumed) : null,
            'percent_of_target' => $targets ? $this->percentOfTarget($targets, $consumed) : null,
        ];
    }

    public function forUser(User $user): CoachContext
    {
        $timezone = $user->tz();
        $today = $user->today();
        $now = Carbon::now($timezone);

        $progress = $this->todayProgress($user);

        $todayMeals = $progress['meals'];
        $consumed = $progress['consumed'];
        $targets = $progress['targets'];

        // One aggregation serves both the daily series and the summary.
        $report = $this->analytics->report(
            $user,
            $today->copy()->subDays(self::TREND_DAYS - 1),
            $today,
        );

        $streak = $this->streaks->forUser($user);

        return new CoachContext(
            date: $today->toDateString(),
            weekday: $today->format('l'),
            partOfDay: $this->partOfDay($now),
            goal: $user->activeNutritionGoal?->goal_type->label(),
            targets: $targets,
            consumed: $consumed,
            remaining: $progress['remaining'],
            percentOfTarget: $progress['percent_of_target'],
            todayMeals: $this->mealRows($todayMeals, $timezone),
            recentMeals: $this->recentMeals($user, $today, $timezone),
            largestRecentMeal: $this->largestRecentMeal($user, $today, $timezone),
            lastSevenDays: $report['series'],
            weekSummary: $this->weekSummary($report),
            // The 14-day activity strip is for the dashboard, not the model.
            streak: [
                'current_days' => $streak['current'],
                'longest_days' => $streak['longest'],
                'logged_today' => $streak['logged_today'],
                'total_days_logged' => $streak['total_days_logged'],
            ],
            hasAnyMeals: $user->meals()->logged()->exists(),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Today                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param  Collection<int, Meal>  $meals
     * @return array{calories:int, protein:float, carbs:float, fat:float}
     */
    private function totals(Collection $meals): array
    {
        return [
            'calories' => (int) $meals->sum('total_calories'),
            'protein' => round((float) $meals->sum('total_protein'), 1),
            'carbs' => round((float) $meals->sum('total_carbs'), 1),
            'fat' => round((float) $meals->sum('total_fat'), 1),
        ];
    }

    /**
     * Remaining macros. Deliberately allowed to go negative — "you are 180
     * kcal over" is a real answer the coach needs, and clamping it at zero
     * would hide it.
     *
     * @param  array{calories:int, protein:int, carbs:int, fat:int}  $targets
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $consumed
     * @return array{calories:int, protein:float, carbs:float, fat:float}
     */
    private function remaining(array $targets, array $consumed): array
    {
        return [
            'calories' => $targets['calories'] - $consumed['calories'],
            'protein' => round($targets['protein'] - $consumed['protein'], 1),
            'carbs' => round($targets['carbs'] - $consumed['carbs'], 1),
            'fat' => round($targets['fat'] - $consumed['fat'], 1),
        ];
    }

    /**
     * @param  array{calories:int, protein:int, carbs:int, fat:int}  $targets
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $consumed
     * @return array{calories:int, protein:int, carbs:int, fat:int}
     */
    private function percentOfTarget(array $targets, array $consumed): array
    {
        $percent = [];

        foreach (['calories', 'protein', 'carbs', 'fat'] as $macro) {
            $target = (float) $targets[$macro];

            $percent[$macro] = $target > 0
                ? (int) round(($consumed[$macro] / $target) * 100)
                : 0;
        }

        return $percent;
    }

    /* ------------------------------------------------------------------ */
    /* History                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The most recent meals from *before* today. Today's meals are carried
     * separately, so nothing appears in the payload twice.
     *
     * @return list<array<string, mixed>>
     */
    private function recentMeals(User $user, Carbon $today, string $timezone): array
    {
        $meals = $user->meals()
            ->logged()
            ->whereDate('consumed_on', '<', $today->toDateString())
            ->orderByDesc('consumed_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_MEALS)
            ->get();

        return $this->mealRows($meals, $timezone, withDate: true);
    }

    /**
     * The biggest meal by calories in the last seven days.
     *
     * Resolved with a query rather than by asking the model to scan a list:
     * "what was my highest-calorie meal this week?" then has one correct
     * answer that does not depend on the model reading carefully.
     *
     * @return array<string, mixed>|null
     */
    private function largestRecentMeal(User $user, Carbon $today, string $timezone): ?array
    {
        $meal = $user->meals()
            ->logged()
            ->whereDate('consumed_on', '>=', $today->copy()->subDays(self::TREND_DAYS - 1)->toDateString())
            ->orderByDesc('total_calories')
            ->orderByDesc('id')
            ->first();

        if ($meal === null) {
            return null;
        }

        return $this->mealRow($meal, $timezone, withDate: true);
    }

    /**
     * @param  Collection<int, Meal>  $meals
     * @return list<array<string, mixed>>
     */
    private function mealRows(Collection $meals, string $timezone, bool $withDate = false): array
    {
        return $meals
            ->map(fn (Meal $meal) => $this->mealRow($meal, $timezone, $withDate))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function mealRow(Meal $meal, string $timezone, bool $withDate = false): array
    {
        $row = [
            'name' => $meal->meal_name,
            'meal_type' => $meal->meal_type->value,
            'time' => $meal->consumed_at?->copy()->setTimezone($timezone)->format('H:i'),
            'calories' => $meal->total_calories,
            'protein' => round((float) $meal->total_protein, 1),
            'carbs' => round((float) $meal->total_carbs, 1),
            'fat' => round((float) $meal->total_fat, 1),
        ];

        if ($withDate) {
            $row = ['date' => $meal->consumed_on?->toDateString(), ...$row];
        }

        return $row;
    }

    /**
     * The seven-day picture, lifted straight out of the Analytics report so
     * the coach and the Analytics screen cannot disagree.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function weekSummary(array $report): array
    {
        $summary = $report['summary'];
        $adherence = $summary['target_adherence'];

        return [
            'from' => $report['range']['from'],
            'to' => $report['range']['to'],
            'days_logged' => $summary['days_logged'],
            'days_in_range' => $summary['days_in_range'],
            'meals_logged' => $summary['total_meals'],
            // Averaged over logged days only — the denominator is stated so
            // the model does not present it as a per-calendar-day figure.
            'average_per_logged_day' => $summary['averages'],
            'days_close_to_calorie_target' => $adherence['days_close_to_target'],
            'target_tolerance_percent' => $adherence['tolerance_percent'],
            'percent_of_logged_days_close_to_target' => $adherence['percent'],
        ];
    }

    private function partOfDay(Carbon $now): string
    {
        return match (true) {
            $now->hour < 5 => 'late night',
            $now->hour < 11 => 'morning',
            $now->hour < 15 => 'midday',
            $now->hour < 18 => 'afternoon',
            $now->hour < 22 => 'evening',
            default => 'late evening',
        };
    }
}
