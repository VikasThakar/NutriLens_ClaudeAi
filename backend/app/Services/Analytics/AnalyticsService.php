<?php

namespace App\Services\Analytics;

use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aggregates a user's real logged meals into the series and summary figures
 * the Analytics screen draws. Nothing here invents a value: a day with no
 * meals is reported as a day with no meals.
 */
class AnalyticsService
{
    /**
     * Selectable ranges, in days (inclusive of today).
     *
     * @var array<string, int>
     */
    public const RANGES = [
        'week' => 7,
        'month' => 30,
        'quarter' => 90,
        'year' => 365,
    ];

    /**
     * Above this many days, daily points stop being readable — especially on a
     * phone — so the series is bucketed by week instead.
     */
    private const WEEKLY_BUCKET_THRESHOLD = 45;

    /**
     * A day counts as "close to your target" when its total calories land
     * within this fraction of the calorie target. One number, applied the same
     * way everywhere, and shown to the user rather than hidden.
     */
    public const TARGET_TOLERANCE = 0.10;

    public function __construct(private readonly DailyNutritionAggregator $aggregator)
    {
    }

    /** @return array{from:Carbon, to:Carbon} */
    public function resolveRange(User $user, string $range): array
    {
        $days = self::RANGES[$range] ?? self::RANGES['week'];
        $to = $user->today();

        return [
            'from' => $to->copy()->subDays($days - 1),
            'to' => $to,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(User $user, Carbon $from, Carbon $to): array
    {
        $days = $this->aggregator->forRange($user, $from, $to);
        $goal = $user->activeNutritionGoal;

        $dayCount = $days->count();
        $granularity = $dayCount > self::WEEKLY_BUCKET_THRESHOLD ? 'week' : 'day';

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $dayCount,
                'granularity' => $granularity,
            ],
            'targets' => $goal ? [
                'calories' => $goal->calorie_target,
                'protein' => $goal->protein_target,
                'carbs' => $goal->carb_target,
                'fat' => $goal->fat_target,
            ] : null,
            'series' => $granularity === 'week'
                ? $this->weeklySeries($days)
                : $days->values()->all(),
            'summary' => $this->summary($days, $goal),
        ];
    }

    /**
     * Summary statistics.
     *
     * Averages are **per day on which something was logged**, not per calendar
     * day in the range. Dividing a week's intake by seven when only three days
     * were logged reports an average nobody ate, so `days_logged` is returned
     * alongside every average to make the denominator explicit.
     *
     * @param  Collection<string, array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function summary(Collection $days, ?NutritionGoal $goal): array
    {
        $logged = $days->filter(fn (array $day) => $day['logged']);
        $loggedCount = $logged->count();

        $average = function (string $key) use ($logged, $loggedCount): float {
            if ($loggedCount === 0) {
                return 0.0;
            }

            return round($logged->sum($key) / $loggedCount, 1);
        };

        $closeToTarget = $this->daysCloseToTarget($logged, $goal?->calorie_target);

        return [
            'days_in_range' => $days->count(),
            'days_logged' => $loggedCount,
            'total_meals' => (int) $days->sum('meals'),
            'averages' => [
                'calories' => (int) round($average('calories')),
                'protein' => $average('protein'),
                'carbs' => $average('carbs'),
                'fat' => $average('fat'),
            ],
            'totals' => [
                'calories' => (int) $days->sum('calories'),
                'protein' => round((float) $days->sum('protein'), 1),
                'carbs' => round((float) $days->sum('carbs'), 1),
                'fat' => round((float) $days->sum('fat'), 1),
            ],
            'target_adherence' => [
                'days_close_to_target' => $closeToTarget,
                'days_logged' => $loggedCount,
                'tolerance_percent' => (int) round(self::TARGET_TOLERANCE * 100),
                'calorie_target' => $goal?->calorie_target,
                // Share of *logged* days that landed in the band. Null when
                // there is no target or nothing logged — not zero, which would
                // read as "you missed every day".
                'percent' => ($goal && $loggedCount > 0)
                    ? (int) round(($closeToTarget / $loggedCount) * 100)
                    : null,
            ],
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $loggedDays
     */
    private function daysCloseToTarget(Collection $loggedDays, ?int $calorieTarget): int
    {
        if (! $calorieTarget || $calorieTarget <= 0) {
            return 0;
        }

        $tolerance = $calorieTarget * self::TARGET_TOLERANCE;

        return $loggedDays
            ->filter(fn (array $day) => abs($day['calories'] - $calorieTarget) <= $tolerance)
            ->count();
    }

    /**
     * Bucket daily rows into weeks starting Monday. Each bucket reports the
     * average of its *logged* days, so a partial week is not dragged toward
     * zero by the days that have not happened yet.
     *
     * @param  Collection<string, array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function weeklySeries(Collection $days): array
    {
        $buckets = [];

        foreach ($days as $day) {
            $weekStart = Carbon::parse($day['date'])->startOfWeek(Carbon::MONDAY)->toDateString();

            $buckets[$weekStart] ??= [
                'date' => $weekStart,
                'calories' => 0,
                'protein' => 0.0,
                'carbs' => 0.0,
                'fat' => 0.0,
                'meals' => 0,
                'days_logged' => 0,
            ];

            $buckets[$weekStart]['meals'] += $day['meals'];

            if (! $day['logged']) {
                continue;
            }

            $buckets[$weekStart]['days_logged']++;
            $buckets[$weekStart]['calories'] += $day['calories'];
            $buckets[$weekStart]['protein'] += $day['protein'];
            $buckets[$weekStart]['carbs'] += $day['carbs'];
            $buckets[$weekStart]['fat'] += $day['fat'];
        }

        ksort($buckets);

        return array_values(array_map(function (array $bucket) {
            $divisor = max(1, $bucket['days_logged']);

            return [
                'date' => $bucket['date'],
                'calories' => (int) round($bucket['calories'] / $divisor),
                'protein' => round($bucket['protein'] / $divisor, 1),
                'carbs' => round($bucket['carbs'] / $divisor, 1),
                'fat' => round($bucket['fat'] / $divisor, 1),
                'meals' => $bucket['meals'],
                'days_logged' => $bucket['days_logged'],
                'logged' => $bucket['days_logged'] > 0,
            ];
        }, $buckets));
    }
}
