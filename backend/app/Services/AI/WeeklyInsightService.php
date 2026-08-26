<?php

namespace App\Services\AI;

use App\Enums\MealStatus;
use App\Enums\MealType;
use App\Models\User;
use App\Models\WeeklyInsight;
use App\Services\AI\Contracts\NutritionInsightGenerator;
use App\Services\AI\Data\GeneratedInsight;
use App\Services\AI\Data\WeeklyNutritionSummary;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\DailyNutritionAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Weekly AI insights, end to end.
 *
 * The order of operations is the point of this class:
 *
 *  1. Read the user's real meals out of MySQL and aggregate them.
 *  2. Refuse to call the AI at all if the week is too thin to say anything
 *     truthful about.
 *  3. Fingerprint the aggregates. If a stored insight for that week was
 *     generated from the same numbers, return it instead of paying for another
 *     call.
 *  4. Send only the aggregates — never meals, photos or identity.
 *  5. Validate what comes back: shape, length, no medical framing, and every
 *     number traceable to the figures we supplied.
 *  6. Store it.
 */
class WeeklyInsightService
{
    /**
     * A week needs at least this many logged days before an insight is worth
     * generating. Two days is not a week, and a summary built on it would have
     * to either overreach or say nothing.
     */
    public const MIN_DAYS_FOR_INSIGHT = 3;

    /** The previous week needs the same footing before it is compared against. */
    public const MIN_DAYS_FOR_COMPARISON = 3;

    /**
     * Wording that would turn a nutrition summary into a medical claim. The
     * prompt forbids all of it; this is the check that a response actually
     * complied.
     */
    private const FORBIDDEN_FRAGMENTS = [
        'diagnos',
        'disease',
        'disorder',
        'prescrib',
        'prescription',
        'symptom',
        'medication',
        'clinically',
        'deficiency',
        'doctor',
        'physician',
        'medical advice',
    ];

    public function __construct(
        private readonly NutritionInsightGenerator $generator,
        private readonly DailyNutritionAggregator $aggregator,
        private readonly WeeklyInsightPrompt $prompt,
    ) {
    }

    /** Monday-to-Sunday window containing the given date, in the user's timezone. */
    public function weekWindow(User $user, ?string $date = null): array
    {
        $anchor = $date !== null
            ? Carbon::createFromFormat('Y-m-d', $date, $user->tz())->startOfDay()
            : $user->today();

        return [
            'start' => $anchor->copy()->startOfWeek(Carbon::MONDAY),
            'end' => $anchor->copy()->startOfWeek(Carbon::MONDAY)->addDays(6),
        ];
    }

    /**
     * Generate (or reuse) the insight for the week containing $date.
     *
     * @return array{status:string, insight:?WeeklyInsight, reused:bool, aggregates:array<string, mixed>, requirement:array<string, int>}
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function generateFor(User $user, ?string $date = null, bool $force = false): array
    {
        $window = $this->weekWindow($user, $date);
        $summary = $this->buildSummary($user, $window['start'], $window['end']);

        $aggregates = $this->aggregatesFor($summary);
        $requirement = [
            'min_days_logged' => self::MIN_DAYS_FOR_INSIGHT,
            'days_logged' => $summary->daysLogged,
        ];

        if ($summary->daysLogged < self::MIN_DAYS_FOR_INSIGHT) {
            return [
                'status' => 'insufficient_data',
                'insight' => null,
                'reused' => false,
                'aggregates' => $aggregates,
                'requirement' => $requirement,
            ];
        }

        $hash = $this->fingerprint($summary);

        $existing = $user->weeklyInsights()
            ->whereDate('week_start', $window['start']->toDateString())
            ->first();

        // Same week, same numbers, already written — there is nothing for the
        // model to add, so do not call it.
        if ($existing && ! $force && $existing->data_hash === $hash && $existing->summary) {
            return [
                'status' => 'ok',
                'insight' => $existing,
                'reused' => true,
                'aggregates' => $aggregates,
                'requirement' => $requirement,
            ];
        }

        $generated = $this->validate($this->generator->generate($summary), $summary);

        return [
            'status' => 'ok',
            'insight' => $this->store($user, $summary, $generated, $hash),
            'reused' => false,
            'aggregates' => $aggregates,
            'requirement' => $requirement,
        ];
    }

    /**
     * The aggregated week, with no AI involved. Used by the Insights screen to
     * show the figures before (or without) a generated summary.
     *
     * @return array{summary:WeeklyNutritionSummary, aggregates:array<string, mixed>}
     */
    public function preview(User $user, ?string $date = null): array
    {
        $window = $this->weekWindow($user, $date);
        $summary = $this->buildSummary($user, $window['start'], $window['end']);

        return [
            'summary' => $summary,
            'aggregates' => $this->aggregatesFor($summary),
        ];
    }

    /**
     * Whether the stored insight for a week still matches the current data.
     * A false here is what makes the UI offer "Regenerate" rather than
     * silently showing a summary that describes edited meals.
     */
    public function isStale(User $user, WeeklyInsight $insight): bool
    {
        $start = Carbon::parse($insight->week_start->toDateString(), $user->tz())->startOfDay();
        $summary = $this->buildSummary($user, $start, $start->copy()->addDays(6));

        return $insight->data_hash !== $this->fingerprint($summary);
    }

    /* ------------------------------------------------------------------ */
    /* Aggregation                                                         */
    /* ------------------------------------------------------------------ */

    public function buildSummary(User $user, Carbon $start, Carbon $end): WeeklyNutritionSummary
    {
        $days = $this->aggregator->forRange($user, $start, $end)->values();

        $rows = $days->map(fn (array $day) => [
            'date' => $day['date'],
            'weekday' => Carbon::parse($day['date'])->format('l'),
            'logged' => $day['logged'],
            'calories' => $day['calories'],
            'protein' => $day['protein'],
            'carbs' => $day['carbs'],
            'fat' => $day['fat'],
            'meals' => $day['meals'],
        ])->all();

        $logged = $days->filter(fn (array $day) => $day['logged']);
        $averages = $this->averages($logged);

        $goal = $user->activeNutritionGoal;
        $targets = $goal ? [
            'calories' => $goal->calorie_target,
            'protein' => $goal->protein_target,
            'carbs' => $goal->carb_target,
            'fat' => $goal->fat_target,
        ] : null;

        return new WeeklyNutritionSummary(
            weekStart: $start->toDateString(),
            weekEnd: $end->toDateString(),
            daysLogged: $logged->count(),
            mealsLogged: (int) $days->sum('meals'),
            averages: $averages,
            targets: $targets,
            daysCloseToTarget: $this->daysCloseToTarget($logged, $goal?->calorie_target),
            tolerancePercent: (int) round(AnalyticsService::TARGET_TOLERANCE * 100),
            days: $rows,
            mealTypeCounts: $this->mealTypeCounts($user, $start, $end),
            weekdayAverageCalories: $this->averageCaloriesFor($logged, weekend: false),
            weekendAverageCalories: $this->averageCaloriesFor($logged, weekend: true),
            calorieSpread: $this->calorieSpread($logged),
            previous: $this->previousWeek($user, $start),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $logged
     * @return array{calories:int, protein:float, carbs:float, fat:float}
     */
    private function averages(\Illuminate\Support\Collection $logged): array
    {
        $count = $logged->count();

        if ($count === 0) {
            return ['calories' => 0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        }

        return [
            'calories' => (int) round($logged->sum('calories') / $count),
            'protein' => round($logged->sum('protein') / $count, 1),
            'carbs' => round($logged->sum('carbs') / $count, 1),
            'fat' => round($logged->sum('fat') / $count, 1),
        ];
    }

    /** @param \Illuminate\Support\Collection<int, array<string, mixed>> $logged */
    private function daysCloseToTarget(\Illuminate\Support\Collection $logged, ?int $target): int
    {
        if (! $target || $target <= 0) {
            return 0;
        }

        $tolerance = $target * AnalyticsService::TARGET_TOLERANCE;

        return $logged->filter(fn (array $day) => abs($day['calories'] - $target) <= $tolerance)->count();
    }

    /** @param \Illuminate\Support\Collection<int, array<string, mixed>> $logged */
    private function averageCaloriesFor(\Illuminate\Support\Collection $logged, bool $weekend): ?int
    {
        $subset = $logged->filter(function (array $day) use ($weekend) {
            $isWeekend = Carbon::parse($day['date'])->isWeekend();

            return $weekend ? $isWeekend : ! $isWeekend;
        });

        if ($subset->isEmpty()) {
            return null;
        }

        return (int) round($subset->sum('calories') / $subset->count());
    }

    /**
     * Population standard deviation of the logged days' calories — a single
     * number for "how consistent was the week". Null below two days, where
     * spread is meaningless.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $logged
     */
    private function calorieSpread(\Illuminate\Support\Collection $logged): ?int
    {
        $count = $logged->count();

        if ($count < 2) {
            return null;
        }

        $mean = $logged->sum('calories') / $count;
        $variance = $logged->reduce(
            fn (float $carry, array $day) => $carry + (($day['calories'] - $mean) ** 2),
            0.0,
        ) / $count;

        return (int) round(sqrt($variance));
    }

    /** @return array<string, int> */
    private function mealTypeCounts(User $user, Carbon $start, Carbon $end): array
    {
        $query = $this->aggregator->scopedQuery($user)
            ->selectRaw('meal_type, COUNT(*) as total')
            ->groupBy('meal_type');

        $counts = $this->aggregator->constrainToRange($query, $start, $end)
            ->pluck('total', 'meal_type');

        $result = [];

        foreach (MealType::cases() as $type) {
            $result[$type->value] = (int) ($counts[$type->value] ?? 0);
        }

        return $result;
    }

    /**
     * The previous week's aggregates, but only when that week has enough
     * logged days to be a fair comparison. Otherwise null, and the prompt is
     * told there is nothing to compare against.
     *
     * @return array{days_logged:int, meals_logged:int, averages:array{calories:int, protein:float, carbs:float, fat:float}}|null
     */
    private function previousWeek(User $user, Carbon $start): ?array
    {
        $previousStart = $start->copy()->subWeek();
        $days = $this->aggregator->forRange($user, $previousStart, $previousStart->copy()->addDays(6))->values();
        $logged = $days->filter(fn (array $day) => $day['logged']);

        if ($logged->count() < self::MIN_DAYS_FOR_COMPARISON) {
            return null;
        }

        return [
            'week_start' => $previousStart->toDateString(),
            'days_logged' => $logged->count(),
            'meals_logged' => (int) $days->sum('meals'),
            'averages' => $this->averages($logged),
        ];
    }

    /** @return array<string, mixed> */
    private function aggregatesFor(WeeklyNutritionSummary $summary): array
    {
        return [
            'week_start' => $summary->weekStart,
            'week_end' => $summary->weekEnd,
            'days_logged' => $summary->daysLogged,
            'meals_logged' => $summary->mealsLogged,
            'averages' => $summary->averages,
            'targets' => $summary->targets,
            'days_close_to_target' => $summary->daysCloseToTarget,
            'tolerance_percent' => $summary->tolerancePercent,
            'days' => $summary->days,
            'meals_by_type' => $summary->mealTypeCounts,
            'weekday_average_calories' => $summary->weekdayAverageCalories,
            'weekend_average_calories' => $summary->weekendAverageCalories,
            'calorie_spread' => $summary->calorieSpread,
            'previous_week' => $summary->previous,
        ];
    }

    private function fingerprint(WeeklyNutritionSummary $summary): string
    {
        return hash('sha256', (string) json_encode($summary->toPayload()));
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws AiResponseException
     */
    private function validate(array $payload, WeeklyNutritionSummary $summary): GeneratedInsight
    {
        $validator = Validator::make($payload, [
            'headline' => ['required', 'string', 'max:120'],
            'summary' => ['required', 'string', 'min:20', 'max:900'],
            'observations' => ['present', 'array', 'min:1', 'max:4'],
            'observations.*' => ['required', 'string', 'max:280'],
            'suggestions' => ['present', 'array', 'max:3'],
            'suggestions.*' => ['required', 'string', 'max:280'],
        ]);

        if ($validator->fails()) {
            Log::warning('Weekly insight failed schema validation', [
                'provider' => $this->generator->providerName(),
                'errors' => $validator->errors()->toArray(),
            ]);

            throw new AiResponseException('The AI response did not match the expected schema.');
        }

        /** @var array{headline:string, summary:string, observations:list<string>, suggestions:list<string>} $clean */
        $clean = $validator->validated();

        $headline = $this->cleanText($clean['headline'], 90);
        $body = $this->cleanText($clean['summary'], 900);
        $observations = $this->cleanList($clean['observations']);
        $suggestions = $this->cleanList($clean['suggestions']);

        $allText = implode(' ', [$headline, $body, ...$observations, ...$suggestions]);

        $this->rejectMedicalFraming($allText);
        $this->rejectUntraceableNumbers($allText, $summary);

        return new GeneratedInsight(
            headline: rtrim($headline, '.'),
            summary: $body,
            observations: $observations,
            suggestions: $suggestions,
            provider: $this->generator->providerName(),
            model: $this->generator->modelName(),
        );
    }

    /** @throws AiResponseException */
    private function rejectMedicalFraming(string $text): void
    {
        $lower = Str::lower($text);

        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                Log::warning('Weekly insight rejected for medical framing', [
                    'provider' => $this->generator->providerName(),
                    'fragment' => $fragment,
                ]);

                throw new AiResponseException('The AI response strayed into medical territory and was discarded.');
            }
        }
    }

    /**
     * Every number in the prose has to be one we handed the model.
     *
     * Integers up to 31 are exempt: those are day counts, dates and "3 of 7
     * days", which are legitimately derived rather than quoted. Everything
     * else must match a supplied figure within a rounding tolerance, so a
     * model that writes "you averaged 210 g of protein" over data that says
     * 140 g is rejected rather than stored.
     *
     * @throws AiResponseException
     */
    private function rejectUntraceableNumbers(string $text, WeeklyNutritionSummary $summary): void
    {
        // ISO dates would otherwise contribute their year as a stray number.
        $stripped = (string) preg_replace('/\d{4}-\d{2}-\d{2}/', '', $text);

        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $stripped, $matches);

        $allowed = $summary->numericValues();
        $allowed[] = (float) Carbon::parse($summary->weekStart)->year;
        $allowed[] = (float) Carbon::parse($summary->weekEnd)->year;

        foreach ($matches[0] as $raw) {
            $value = (float) str_replace(',', '', $raw);

            // Day counts, dates, ordinals.
            if ($value <= 31 && floor($value) === $value) {
                continue;
            }

            if (! $this->matchesAny($value, $allowed)) {
                Log::warning('Weekly insight rejected for an untraceable number', [
                    'provider' => $this->generator->providerName(),
                    'value' => $value,
                ]);

                throw new AiResponseException(
                    'The AI response contained a figure that is not in your data, so it was discarded.'
                );
            }
        }
    }

    /** @param list<float> $allowed */
    private function matchesAny(float $value, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            $difference = abs($value - $candidate);

            // Absolute slack covers rounding a 140.4 to 140; relative slack
            // covers rounding 1,847 kcal to 1,850.
            if ($difference <= 1.0 || ($candidate > 0 && $difference / $candidate <= 0.02)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $values @return list<string> */
    private function cleanList(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => $this->cleanText($value, 280),
            $values,
        ), fn (string $value) => $value !== ''));
    }

    /** Strip markdown and collapse whitespace — insights render as plain text. */
    private function cleanText(string $text, int $limit): string
    {
        $text = (string) preg_replace('/[*_`#]+/', '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return Str::limit($text, $limit, '');
    }

    /* ------------------------------------------------------------------ */
    /* Persistence                                                         */
    /* ------------------------------------------------------------------ */

    private function store(
        User $user,
        WeeklyNutritionSummary $summary,
        GeneratedInsight $insight,
        string $hash,
    ): WeeklyInsight {
        $adherence = ($summary->targets !== null && $summary->daysLogged > 0)
            ? round(($summary->daysCloseToTarget / $summary->daysLogged) * 100, 2)
            : null;

        // whereDate rather than a plain equality on `week_start`: MySQL stores
        // it as a DATE, SQLite as `Y-m-d 00:00:00` text, and only whereDate
        // matches the stored value on both. Without it the unique index on
        // (user_id, week_start) is hit instead of the existing row being found.
        $existing = $user->weeklyInsights()
            ->whereDate('week_start', $summary->weekStart)
            ->first();

        $attributes = [
            'week_end' => $summary->weekEnd,
            'headline' => $insight->headline,
            'summary' => $insight->summary,
            'highlights' => $insight->observations,
            'recommendations' => $insight->suggestions,
            'comparison' => $summary->previous,
            'meals_logged' => $summary->mealsLogged,
            'days_logged' => $summary->daysLogged,
            'days_close_to_target' => $summary->daysCloseToTarget,
            'calorie_target' => $summary->targets['calories'] ?? null,
            'avg_calories' => $summary->averages['calories'],
            'avg_protein' => $summary->averages['protein'],
            'avg_carbs' => $summary->averages['carbs'],
            'avg_fat' => $summary->averages['fat'],
            'goal_adherence' => $adherence,
            'generated_at' => now(),
            'ai_provider' => $insight->provider,
            'ai_model' => $insight->model,
            'data_hash' => $hash,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing;
        }

        return $user->weeklyInsights()->create([
            'week_start' => $summary->weekStart,
            ...$attributes,
        ]);
    }
}
