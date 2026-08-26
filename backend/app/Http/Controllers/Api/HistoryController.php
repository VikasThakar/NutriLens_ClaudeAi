<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Http\Resources\NutritionGoalResource;
use App\Models\Meal;
use App\Services\Analytics\DailyNutritionAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Meal history, browsed one day at a time.
 *
 * Days are keyed on `meals.consumed_on` — the date resolved in the user's own
 * timezone when the meal was saved — so "Tuesday" means the same thing here as
 * it does on the dashboard and in the streak count.
 */
class HistoryController extends Controller
{
    public function __construct(private readonly DailyNutritionAggregator $aggregator)
    {
    }

    /**
     * GET /api/history/day?date=YYYY-MM-DD
     *
     * One day: its totals, its meals in the order they were eaten, and the
     * nearest logged days either side so the client can offer "jump to the
     * previous day I actually ate" instead of walking through empty dates.
     */
    public function day(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();

        $date = isset($validated['date'])
            ? Carbon::createFromFormat('Y-m-d', $validated['date'], $user->tz())->startOfDay()
            : $user->today();

        $meals = $user->meals()
            ->logged()
            ->onDate($date->toDateString())
            ->with(['items', 'image'])
            ->orderBy('consumed_at')
            ->orderBy('id')
            ->get();

        $goal = $user->activeNutritionGoal;
        $totals = $this->totals($meals);

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'is_today' => $date->isSameDay($user->today()),
                'is_future' => $date->greaterThan($user->today()),
                'goal' => $goal ? NutritionGoalResource::make($goal) : null,
                'totals' => $totals,
                'remaining' => $goal ? [
                    'calories' => $goal->calorie_target - $totals['calories'],
                    'protein' => round($goal->protein_target - $totals['protein'], 1),
                    'carbs' => round($goal->carb_target - $totals['carbs'], 1),
                    'fat' => round($goal->fat_target - $totals['fat'], 1),
                ] : null,
                'meal_count' => $meals->count(),
                'meals' => MealResource::collection($meals),
                'previous_logged_date' => $this->adjacentLoggedDate($request, $date, 'before'),
                'next_logged_date' => $this->adjacentLoggedDate($request, $date, 'after'),
            ],
        ]);
    }

    /**
     * GET /api/history/calendar?month=YYYY-MM
     *
     * Which days in a month have meals, and how much was logged on each. Drives
     * the date picker's activity dots without loading a month of meals.
     */
    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $user = $request->user();

        $anchor = isset($validated['month'])
            ? Carbon::createFromFormat('Y-m', $validated['month'], $user->tz())->startOfMonth()
            : $user->today()->startOfMonth();

        $days = $this->aggregator->forRange(
            $user,
            $anchor->copy()->startOfMonth(),
            $anchor->copy()->endOfMonth()->startOfDay(),
        );

        return response()->json([
            'data' => [
                'month' => $anchor->format('Y-m'),
                'days' => $days->values()->all(),
                'days_logged' => $days->filter(fn (array $day) => $day['logged'])->count(),
                'total_meals' => (int) $days->sum('meals'),
            ],
        ]);
    }

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
     * The closest date either side of $date on which this user logged
     * something. Scoped through $user->meals(), so it can only ever see the
     * caller's own history.
     */
    private function adjacentLoggedDate(Request $request, Carbon $date, string $direction): ?string
    {
        $query = $request->user()->meals()->logged();

        // Half-open bounds rather than `<` / `>` on a bare date string: SQLite
        // stores `consumed_on` as `Y-m-d 00:00:00` text, where a same-day row
        // compares as greater than the bare date and would be returned as the
        // "next" day. See DailyNutritionAggregator::constrainToRange().
        $value = $direction === 'before'
            ? $query->where('consumed_on', '<', $date->toDateString())->max('consumed_on')
            : $query->where('consumed_on', '>=', $date->copy()->addDay()->toDateString())->min('consumed_on');

        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
