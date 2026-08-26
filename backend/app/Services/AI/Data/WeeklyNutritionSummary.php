<?php

namespace App\Services\AI\Data;

/**
 * Everything — and only what — the AI is told about a user's week.
 *
 * This object is the privacy and correctness boundary for weekly insights. It
 * carries aggregates: day counts, averages, targets, per-day calorie totals and
 * the same figures for the previous week. It deliberately does not carry meal
 * names, photos, notes, the user's name, their email or their body metrics.
 *
 * It is also the source of truth for validation: every number the model writes
 * has to be traceable back to `numericValues()`.
 */
readonly class WeeklyNutritionSummary
{
    /**
     * @param  list<array{date:string, weekday:string, logged:bool, calories:int, protein:float, carbs:float, fat:float, meals:int}>  $days
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $averages
     * @param  array{calories:int, protein:int, carbs:int, fat:int}|null  $targets
     * @param  array<string, int>  $mealTypeCounts
     * @param  array{days_logged:int, meals_logged:int, averages:array{calories:int, protein:float, carbs:float, fat:float}}|null  $previous
     */
    public function __construct(
        public string $weekStart,
        public string $weekEnd,
        public int $daysLogged,
        public int $mealsLogged,
        public array $averages,
        public ?array $targets,
        public int $daysCloseToTarget,
        public int $tolerancePercent,
        public array $days,
        public array $mealTypeCounts,
        public ?int $weekdayAverageCalories,
        public ?int $weekendAverageCalories,
        public ?int $calorieSpread,
        public ?array $previous,
    ) {
    }

    /**
     * The payload sent to the model. Flat, labelled and small — a compact
     * prompt is cheaper, and there is nothing here the model needs that is not
     * a number or a date.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'week' => ['start' => $this->weekStart, 'end' => $this->weekEnd],
            'days_logged' => $this->daysLogged,
            'meals_logged' => $this->mealsLogged,
            'daily_averages_on_logged_days' => $this->averages,
            'daily_targets' => $this->targets,
            'days_close_to_calorie_target' => $this->daysCloseToTarget,
            'target_tolerance_percent' => $this->tolerancePercent,
            'per_day' => array_map(fn (array $day) => [
                'date' => $day['date'],
                'weekday' => $day['weekday'],
                'logged' => $day['logged'],
                'calories' => $day['calories'],
                'protein' => $day['protein'],
                'carbs' => $day['carbs'],
                'fat' => $day['fat'],
                'meals' => $day['meals'],
            ], $this->days),
            'meals_by_type' => $this->mealTypeCounts,
            'weekday_average_calories' => $this->weekdayAverageCalories,
            'weekend_average_calories' => $this->weekendAverageCalories,
            'calorie_spread_on_logged_days' => $this->calorieSpread,
        ];

        $payload['previous_week'] = $this->previous;

        if ($this->previous !== null) {
            $payload['change_vs_previous_week'] = [
                'calories' => $this->averages['calories'] - $this->previous['averages']['calories'],
                'protein' => round($this->averages['protein'] - $this->previous['averages']['protein'], 1),
                'carbs' => round($this->averages['carbs'] - $this->previous['averages']['carbs'], 1),
                'fat' => round($this->averages['fat'] - $this->previous['averages']['fat'], 1),
                'days_logged' => $this->daysLogged - $this->previous['days_logged'],
            ];
        }

        return $payload;
    }

    /**
     * Every number the model is allowed to state, flattened.
     *
     * WeeklyInsightService checks the generated prose against this set, so a
     * model that invents "you averaged 210g of protein" is rejected rather
     * than stored.
     *
     * @return list<float>
     */
    public function numericValues(): array
    {
        $values = [];

        $walk = function (mixed $node) use (&$walk, &$values): void {
            if (is_int($node) || is_float($node)) {
                $values[] = (float) $node;

                return;
            }

            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };

        $walk($this->toPayload());

        // Derived figures a well-behaved summary may legitimately state.
        if ($this->targets !== null) {
            foreach (['calories', 'protein', 'carbs', 'fat'] as $macro) {
                $target = $this->targets[$macro];

                if ($target > 0) {
                    $values[] = round(($this->averages[$macro] / $target) * 100);
                    $values[] = round($this->averages[$macro] - $target, 1);
                    $values[] = round($target - $this->averages[$macro], 1);
                }
            }
        }

        if ($this->previous !== null) {
            foreach (['calories', 'protein', 'carbs', 'fat'] as $macro) {
                $before = $this->previous['averages'][$macro];

                if ($before > 0) {
                    $values[] = round((($this->averages[$macro] - $before) / $before) * 100);
                }
            }
        }

        return array_values(array_unique(array_map(
            fn (float $value) => abs($value),
            $values,
        )));
    }

    /** Whether there is a comparable previous week to talk about. */
    public function hasComparison(): bool
    {
        return $this->previous !== null;
    }
}
