<?php

namespace App\Services\AI\Data;

/**
 * Everything — and only what — the AI Coach is told about a user.
 *
 * This object is the privacy and correctness boundary for the coach, the same
 * role WeeklyNutritionSummary plays for weekly insights:
 *
 *  - **Privacy.** It carries nutrition figures, meal names and dates. It
 *    deliberately does not carry the user's name, email, password hash, API
 *    tokens, database ids, photos, body metrics or timezone. Nothing that
 *    identifies the account ever reaches the provider.
 *
 *  - **Correctness.** Every figure the coach might be asked about is computed
 *    here, in PHP, from the user's real rows: remaining macros, percentages of
 *    target, averages, the largest recent meal. The model is told to quote
 *    these rather than derive them, so a wrong answer cannot come from the
 *    model doing arithmetic badly.
 *
 * Meal *names* are included because questions like "what was my biggest meal
 * this week?" and "why am I missing protein?" cannot be answered without them.
 * That is the same data the photo analyser already sends upstream.
 */
readonly class CoachContext
{
    /**
     * @param  array{calories:int, protein:int, carbs:int, fat:int}|null  $targets
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $consumed
     * @param  array{calories:int, protein:float, carbs:float, fat:float}|null  $remaining
     * @param  array{calories:int, protein:int, carbs:int, fat:int}|null  $percentOfTarget
     * @param  list<array<string, mixed>>  $todayMeals
     * @param  list<array<string, mixed>>  $recentMeals
     * @param  array<string, mixed>|null  $largestRecentMeal
     * @param  list<array<string, mixed>>  $lastSevenDays
     * @param  array<string, mixed>  $weekSummary
     * @param  array<string, mixed>  $streak
     */
    public function __construct(
        public string $date,
        public string $weekday,
        public string $partOfDay,
        public ?string $goal,
        public ?array $targets,
        public array $consumed,
        public ?array $remaining,
        public ?array $percentOfTarget,
        public array $todayMeals,
        public array $recentMeals,
        public ?array $largestRecentMeal,
        public array $lastSevenDays,
        public array $weekSummary,
        public array $streak,
        public bool $hasAnyMeals,
    ) {
    }

    public function hasGoal(): bool
    {
        return $this->targets !== null;
    }

    public function hasMealsToday(): bool
    {
        return $this->todayMeals !== [];
    }

    /** Number of meals logged today. */
    public function mealsToday(): int
    {
        return count($this->todayMeals);
    }

    /**
     * The macro furthest from its target in percentage terms, ignoring
     * calories — the one a coach would actually steer the next meal toward.
     * Null when there is no goal, or nothing is short.
     */
    public function largestGapMacro(): ?string
    {
        if ($this->percentOfTarget === null) {
            return null;
        }

        $gaps = [];

        foreach (['protein', 'carbs', 'fat'] as $macro) {
            $percent = $this->percentOfTarget[$macro];

            if ($percent < 100) {
                $gaps[$macro] = $percent;
            }
        }

        if ($gaps === []) {
            return null;
        }

        asort($gaps);

        return (string) array_key_first($gaps);
    }

    /**
     * The payload sent to the model. Flat, labelled and small.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'current_date' => $this->date,
            'weekday' => $this->weekday,
            'part_of_day' => $this->partOfDay,

            'nutrition_goal' => $this->goal,
            'daily_targets' => $this->targets,

            'today' => [
                'consumed' => $this->consumed,
                'remaining' => $this->remaining,
                'percent_of_target' => $this->percentOfTarget,
                'meals_logged' => $this->mealsToday(),
                'meals' => $this->todayMeals,
            ],

            'recent_meals' => $this->recentMeals,
            'largest_meal_in_last_7_days' => $this->largestRecentMeal,
            'last_7_days' => $this->lastSevenDays,
            'last_7_days_summary' => $this->weekSummary,
            'logging_streak' => $this->streak,

            // Pre-computed so the model never has to do the arithmetic that
            // matters. Anything absent here is genuinely unknown.
            'derived' => [
                'has_daily_targets' => $this->hasGoal(),
                'has_logged_anything_ever' => $this->hasAnyMeals,
                'has_logged_today' => $this->hasMealsToday(),
                'calories_over_target' => $this->remaining !== null
                    ? $this->remaining['calories'] < 0
                    : null,
                'macro_furthest_below_target' => $this->largestGapMacro(),
            ],
        ];
    }

    /**
     * The same figures, shaped for the AI Coach screen's "Today's progress"
     * card. The card and the model therefore read from one source: what the
     * user sees is exactly what the coach was told.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'date' => $this->date,
            'weekday' => $this->weekday,
            'goal' => $this->goal,
            'targets' => $this->targets,
            'consumed' => $this->consumed,
            'remaining' => $this->remaining,
            'percent_of_target' => $this->percentOfTarget,
            'meals_logged_today' => $this->mealsToday(),
            'today_meals' => $this->todayMeals,
            'streak' => $this->streak,
            'week' => $this->weekSummary,
            'has_goal' => $this->hasGoal(),
            'has_any_meals' => $this->hasAnyMeals,
        ];
    }
}
