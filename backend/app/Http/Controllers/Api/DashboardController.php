<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Http\Resources\NutritionGoalResource;
use App\Http\Resources\WeeklyInsightResource;
use App\Models\Meal;
use App\Models\User;
use App\Services\Analytics\DailyNutritionAggregator;
use App\Services\Analytics\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    /** Days shown in the dashboard's trend preview. */
    private const TREND_DAYS = 7;

    public function __construct(
        private readonly StreakService $streaks,
        private readonly DailyNutritionAggregator $aggregator,
    ) {
    }

    /**
     * GET /api/dashboard/today?date=YYYY-MM-DD
     *
     * Everything the Today screen renders in one call: the active goal, the
     * day's logged meals grouped by meal type, the consumed/remaining macro
     * totals, the logging streak, a seven-day trend preview, the most recent
     * meals and the latest weekly summary.
     *
     * The streak, trend and insight are always relative to the user's *actual*
     * today, even when `date` asks for another day — they describe the account,
     * not the requested date. Everything is scoped to the authenticated user.
     */
    public function today(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $timezone = $user->tz();

        $date = $request->filled('date')
            ? Carbon::createFromFormat('Y-m-d', $request->string('date')->toString(), $timezone)
            : Carbon::now($timezone);

        $meals = $user->meals()
            ->logged()
            ->onDate($date->toDateString())
            ->with(['items', 'image'])
            ->orderBy('consumed_at')
            ->get();

        $goal = $user->activeNutritionGoal;

        $consumed = [
            'calories' => (int) $meals->sum('total_calories'),
            'protein' => round((float) $meals->sum('total_protein'), 1),
            'carbs' => round((float) $meals->sum('total_carbs'), 1),
            'fat' => round((float) $meals->sum('total_fat'), 1),
        ];

        $latestInsight = $user->weeklyInsights()->newestFirst()->first();

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'goal' => $goal ? NutritionGoalResource::make($goal) : null,
                'consumed' => $consumed,
                'remaining' => $goal ? [
                    'calories' => $goal->calorie_target - $consumed['calories'],
                    'protein' => round($goal->protein_target - $consumed['protein'], 1),
                    'carbs' => round($goal->carb_target - $consumed['carbs'], 1),
                    'fat' => round($goal->fat_target - $consumed['fat'], 1),
                ] : null,
                'meal_count' => $meals->count(),
                'meals' => MealResource::collection($meals),
                'groups' => $this->groupByMealType($meals),

                'streak' => $this->streaks->forUser($user),
                'trend' => $this->trend($user),
                'recent_meals' => $this->recentMeals($user),
                'latest_insight' => $latestInsight
                    ? WeeklyInsightResource::make($latestInsight)
                    : null,
                // Distinguishes "brand new account" from "quiet day", which the
                // two empty states on Today need to tell apart.
                'has_any_meals' => $user->meals()->logged()->exists(),
            ],
        ]);
    }

    /**
     * The last seven days including today, one row per day — including the
     * days with nothing logged, which are the interesting ones in a trend.
     *
     * @return list<array<string, mixed>>
     */
    private function trend(User $user): array
    {
        $today = $user->today();

        return $this->aggregator
            ->forRange($user, $today->copy()->subDays(self::TREND_DAYS - 1), $today)
            ->values()
            ->all();
    }

    /**
     * The user's most recently eaten meals, regardless of day. On a quiet
     * morning this is what gives the dashboard something real to show.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    private function recentMeals(User $user)
    {
        return MealResource::collection(
            $user->meals()
                ->logged()
                ->with(['items', 'image'])
                ->orderByDesc('consumed_at')
                ->orderByDesc('id')
                ->limit(4)
                ->get()
        );
    }

    /**
     * Meals bucketed into Breakfast / Lunch / Dinner / Snacks with per-group
     * totals. Every bucket is present even when empty, so the client can render
     * a stable layout without inventing the order itself.
     *
     * @param  Collection<int, Meal>  $meals
     * @return list<array<string, mixed>>
     */
    private function groupByMealType(Collection $meals): array
    {
        return collect(MealType::cases())
            ->map(function (MealType $type) use ($meals) {
                $inGroup = $meals->where('meal_type', $type)->values();

                return [
                    'meal_type' => $type->value,
                    'label' => $type === MealType::Snack ? 'Snacks' : $type->label(),
                    'meal_count' => $inGroup->count(),
                    'totals' => [
                        'calories' => (int) $inGroup->sum('total_calories'),
                        'protein' => round((float) $inGroup->sum('total_protein'), 1),
                        'carbs' => round((float) $inGroup->sum('total_carbs'), 1),
                        'fat' => round((float) $inGroup->sum('total_fat'), 1),
                    ],
                    'meals' => MealResource::collection($inGroup),
                ];
            })
            ->all();
    }
}
