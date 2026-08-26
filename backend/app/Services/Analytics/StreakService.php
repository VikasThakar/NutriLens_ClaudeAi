<?php

namespace App\Services\Analytics;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Daily logging streaks.
 *
 * The rule, stated once and applied everywhere:
 *
 *  - **A day counts when the user logged at least one meal on it.** Three
 *    meals on Tuesday is still one day.
 *  - Dates come from `meals.consumed_on`, which was resolved in the user's own
 *    timezone when the meal was saved. Nothing here uses the server clock.
 *  - The **current streak** is the run of consecutive days ending today. If
 *    today has nothing logged yet the run is measured to yesterday instead, so
 *    a streak is not reported as broken at 9am before anyone has eaten. It
 *    only breaks once a full day has been missed.
 *  - The **longest streak** is the longest such run anywhere in the user's
 *    history.
 */
class StreakService
{
    /** Days of activity history returned for the dashboard strip. */
    private const RECENT_DAYS = 14;

    public function __construct(private readonly DailyNutritionAggregator $aggregator)
    {
    }

    /**
     * @return array{
     *     current:int,
     *     longest:int,
     *     logged_today:bool,
     *     total_days_logged:int,
     *     last_logged_on:?string,
     *     recent:list<array{date:string, logged:bool}>
     * }
     */
    public function forUser(User $user): array
    {
        $dates = $this->aggregator->loggedDates($user);
        $set = array_fill_keys($dates, true);

        $today = $user->today();
        $todayKey = $today->toDateString();

        return [
            'current' => $this->currentStreak($set, $today),
            'longest' => $this->longestStreak($dates),
            'logged_today' => isset($set[$todayKey]),
            'total_days_logged' => count($dates),
            'last_logged_on' => $dates === [] ? null : end($dates),
            'recent' => $this->recentActivity($set, $today),
        ];
    }

    /**
     * @param  array<string, true>  $set
     */
    private function currentStreak(array $set, Carbon $today): int
    {
        if ($set === []) {
            return 0;
        }

        // Anchor on today if it has a meal, otherwise on yesterday. Anything
        // older than that means the streak has already been broken.
        $cursor = $today->copy();

        if (! isset($set[$cursor->toDateString()])) {
            $cursor->subDay();

            if (! isset($set[$cursor->toDateString()])) {
                return 0;
            }
        }

        $streak = 0;

        while (isset($set[$cursor->toDateString()])) {
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }

    /**
     * @param  list<string>  $dates  ascending, distinct
     */
    private function longestStreak(array $dates): int
    {
        if ($dates === []) {
            return 0;
        }

        $longest = 1;
        $run = 1;

        for ($i = 1; $i < count($dates); $i++) {
            $previous = Carbon::parse($dates[$i - 1]);
            $current = Carbon::parse($dates[$i]);

            // diffInDays on consecutive calendar dates is exactly 1. Both are
            // parsed at midnight UTC, so DST cannot make it 0 or 2.
            if ($previous->addDay()->toDateString() === $current->toDateString()) {
                $run++;
                $longest = max($longest, $run);
            } else {
                $run = 1;
            }
        }

        return $longest;
    }

    /**
     * @param  array<string, true>  $set
     * @return list<array{date:string, logged:bool}>
     */
    private function recentActivity(array $set, Carbon $today): array
    {
        $days = [];
        $cursor = $today->copy()->subDays(self::RECENT_DAYS - 1);

        for ($i = 0; $i < self::RECENT_DAYS; $i++) {
            $key = $cursor->toDateString();
            $days[] = ['date' => $key, 'logged' => isset($set[$key])];
            $cursor->addDay();
        }

        return $days;
    }
}
