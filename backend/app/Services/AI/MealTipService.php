<?php

namespace App\Services\AI;

use App\Models\Meal;
use App\Models\User;

/**
 * The "NutriLens Tip" shown after a meal is saved and on the meal detail
 * sheet.
 *
 * Deliberately **not** an AI call. The tip is one sentence about how a single
 * meal sits against the day's remaining targets, which is arithmetic — and
 * arithmetic is something we can do exactly, for free, and instantly. Spending
 * a paid request (and a second or two of latency) on every meal save to
 * produce a worse-grounded sentence would be the wrong trade.
 *
 * Everything the tip needs already exists: CoachContextService computes the
 * day's targets, totals and remaining macros, so this class only decides which
 * observation is worth making. When a user wants more than a sentence, the AI
 * Coach is one tap away and *does* call the model.
 */
class MealTipService
{
    /**
     * A meal is treated as protein-led when it carries at least this share of
     * the day's whole protein target.
     */
    private const STRONG_PROTEIN_SHARE = 0.25;

    /** Calories left below this share of the target counts as "nearly there". */
    private const NEARLY_THERE_SHARE = 0.12;

    public function __construct(private readonly CoachContextService $contexts)
    {
    }

    /**
     * @return array{headline:string, body:string, tone:string}
     */
    public function forMeal(User $user, Meal $meal): array
    {
        $progress = $this->contexts->todayProgress($user);

        $isToday = $meal->consumed_on?->toDateString() === $progress['date'];

        if ($progress['targets'] === null) {
            return $this->withoutTargets($meal);
        }

        if (! $isToday) {
            return $this->pastDay($meal);
        }

        return $this->againstTargets($meal, $progress);
    }

    /** @return array{headline:string, body:string, tone:string} */
    private function withoutTargets(Meal $meal): array
    {
        return [
            'headline' => 'Logged',
            'body' => sprintf(
                'This meal came to %s kcal with %sg protein. Set your daily calorie and macro targets and '
                    .'NutriLens can tell you how each meal fits your day.',
                $this->kcal($meal->total_calories),
                $this->grams($meal->total_protein),
            ),
            'tone' => 'neutral',
        ];
    }

    /** @return array{headline:string, body:string, tone:string} */
    private function pastDay(Meal $meal): array
    {
        return [
            'headline' => 'Added to an earlier day',
            'body' => sprintf(
                'This meal was logged against %s, so it counts toward that day rather than today: %s kcal, '
                    .'%sg protein, %sg carbs and %sg fat.',
                $meal->consumed_on?->toDateString() ?? 'an earlier date',
                $this->kcal($meal->total_calories),
                $this->grams($meal->total_protein),
                $this->grams($meal->total_carbs),
                $this->grams($meal->total_fat),
            ),
            'tone' => 'neutral',
        ];
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array{headline:string, body:string, tone:string}
     */
    private function againstTargets(Meal $meal, array $progress): array
    {
        $targets = $progress['targets'];
        $remaining = $progress['remaining'];
        $percent = $progress['percent_of_target'];

        $proteinShare = $targets['protein'] > 0
            ? (float) $meal->total_protein / $targets['protein']
            : 0.0;

        // Over target: say so plainly, without scolding.
        if ($remaining['calories'] < 0) {
            return [
                'headline' => 'Over target for today',
                'body' => sprintf(
                    'Today is now %s kcal past your %s kcal target, at %d%%. %s',
                    $this->kcal(abs($remaining['calories'])),
                    $this->kcal($targets['calories']),
                    $percent['calories'],
                    $remaining['protein'] > 0
                        ? sprintf('Protein is still %sg short, so leaner choices are the ones that help now.', $this->grams($remaining['protein']))
                        : 'Every macro target is met, so nothing more is needed today.',
                ),
                'tone' => 'caution',
            ];
        }

        // A genuinely protein-led meal is the most useful thing to point out.
        if ($proteinShare >= self::STRONG_PROTEIN_SHARE) {
            return [
                'headline' => 'Strong protein choice',
                'body' => sprintf(
                    'That is %sg of protein — %d%% of your daily target in one meal. You are at %sg of %sg, '
                        .'with %sg to go and %s kcal left.',
                    $this->grams($meal->total_protein),
                    (int) round($proteinShare * 100),
                    $this->grams($progress['consumed']['protein']),
                    $this->grams($targets['protein']),
                    $this->grams(max(0, $remaining['protein'])),
                    $this->kcal($remaining['calories']),
                ),
                'tone' => 'positive',
            ];
        }

        // Close to the calorie target with protein outstanding — the one case
        // where the next choice really matters.
        if ($remaining['calories'] <= $targets['calories'] * self::NEARLY_THERE_SHARE) {
            return [
                'headline' => 'Nearly at your calorie target',
                'body' => sprintf(
                    'Only %s kcal left of your %s kcal target. %s',
                    $this->kcal($remaining['calories']),
                    $this->kcal($targets['calories']),
                    $remaining['protein'] > 10
                        ? sprintf('Protein is %sg short, so a lighter, protein-focused option fits the room you have left.', $this->grams($remaining['protein']))
                        : 'Your macros are close to where they should be, so anything else today can be small.',
                ),
                'tone' => 'neutral',
            ];
        }

        $gap = $this->largestGap($remaining, $targets);

        return [
            'headline' => 'Logged',
            'body' => sprintf(
                'This meal takes you to %s kcal of %s kcal today, %d%% of target. %s',
                $this->kcal($progress['consumed']['calories']),
                $this->kcal($targets['calories']),
                $percent['calories'],
                $gap !== null
                    ? sprintf(
                        '%s is the macro furthest behind — %sg of %sg — so it is the one worth aiming at next.',
                        ucfirst($gap),
                        $this->grams($progress['consumed'][$gap]),
                        $this->grams($targets[$gap]),
                    )
                    : 'Protein, carbs and fat are all tracking together.',
            ),
            'tone' => 'neutral',
        ];
    }

    /**
     * The macro furthest below target in percentage terms, calories aside.
     *
     * @param  array<string, mixed>  $remaining
     * @param  array<string, int>  $targets
     */
    private function largestGap(array $remaining, array $targets): ?string
    {
        $shares = [];

        foreach (['protein', 'carbs', 'fat'] as $macro) {
            if ($targets[$macro] > 0 && $remaining[$macro] > 0) {
                $shares[$macro] = $remaining[$macro] / $targets[$macro];
            }
        }

        if ($shares === []) {
            return null;
        }

        arsort($shares);

        return (string) array_key_first($shares);
    }

    private function kcal(int|float $value): string
    {
        return number_format(round($value));
    }

    private function grams(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    }
}
