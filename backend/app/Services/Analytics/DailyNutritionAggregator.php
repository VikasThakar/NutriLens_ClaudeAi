<?php

namespace App\Services\Analytics;

use App\Enums\MealStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a date range into one row per calendar day.
 *
 * Every day in the range is present in the result, including days with no
 * meals — a gap in the data is information, and a chart that silently skips
 * empty days misrepresents the week. Grouping is on `meals.consumed_on`, the
 * date already resolved in the user's timezone when the meal was saved, so a
 * day boundary never shifts underneath the numbers.
 */
class DailyNutritionAggregator
{
    /**
     * @return Collection<string, array{date:string, calories:int, protein:float, carbs:float, fat:float, meals:int, logged:bool}>
     */
    public function forRange(User $user, Carbon $from, Carbon $to): Collection
    {
        $rows = $this->scopedQuery($user)
            ->selectRaw('consumed_on as day')
            ->selectRaw('SUM(total_calories) as calories')
            ->selectRaw('SUM(total_protein) as protein')
            ->selectRaw('SUM(total_carbs) as carbs')
            ->selectRaw('SUM(total_fat) as fat')
            ->selectRaw('COUNT(*) as meals')
            ->tap(fn (QueryBuilder $query) => $this->constrainToRange($query, $from, $to))
            ->groupBy('consumed_on')
            ->get()
            ->keyBy(fn (object $row) => $this->dateKey($row->day));

        $days = collect();

        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $days->put($key, [
                'date' => $key,
                'calories' => $row ? (int) $row->calories : 0,
                'protein' => $row ? round((float) $row->protein, 1) : 0.0,
                'carbs' => $row ? round((float) $row->carbs, 1) : 0.0,
                'fat' => $row ? round((float) $row->fat, 1) : 0.0,
                'meals' => $row ? (int) $row->meals : 0,
                'logged' => $row !== null,
            ]);
        }

        return $days;
    }

    /**
     * Every calendar date on which the user logged at least one meal, as
     * `Y-m-d` strings in ascending order. This is the raw material for streak
     * calculation, so it deliberately reads dates only — not totals.
     *
     * @return list<string>
     */
    public function loggedDates(User $user, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = $this->scopedQuery($user)->distinct()->orderBy('consumed_on');

        $this->constrainToRange($query, $from, $to);

        return $query->pluck('consumed_on')
            ->map(fn ($value) => $this->dateKey($value))
            ->unique()
            ->values()
            ->all();
    }

    /** @return QueryBuilder */
    public function scopedQuery(User $user): QueryBuilder
    {
        return DB::table('meals')
            ->where('user_id', $user->id)
            ->where('status', MealStatus::Logged->value)
            ->whereNull('deleted_at');
    }

    /**
     * Bound a query to a date range on `consumed_on`.
     *
     * Deliberately a half-open range (`>= from`, `< to + 1 day`) rather than a
     * BETWEEN on two date strings. MySQL stores `consumed_on` as a DATE and
     * would compare either form correctly, but SQLite — which the test suite
     * runs on — stores it as `Y-m-d 00:00:00` text, where
     * `'2026-08-25 00:00:00' <= '2026-08-25'` is false and the last day of
     * every range silently disappears. The half-open form is correct on both,
     * and unlike wrapping the column in DATE() it still uses the index.
     */
    public function constrainToRange(QueryBuilder $query, ?Carbon $from, ?Carbon $to): QueryBuilder
    {
        if ($from) {
            $query->where('consumed_on', '>=', $from->toDateString());
        }

        if ($to) {
            $query->where('consumed_on', '<', $to->copy()->addDay()->toDateString());
        }

        return $query;
    }

    /**
     * MySQL hands back `2026-08-25`, SQLite `2026-08-25 00:00:00`. Normalise
     * both to a bare date so the two drivers key identically.
     */
    private function dateKey(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
