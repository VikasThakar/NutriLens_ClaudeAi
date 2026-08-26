<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\NutritionCoach;
use App\Services\AI\Data\CoachContext;
use Illuminate\Support\Str;

/**
 * Offline driver — AI_PROVIDER=fake.
 *
 * Answers from the user's actual CoachContext rather than returning canned
 * prose. It classifies the question into one of a handful of intents, then
 * composes a reply out of the same real figures a hosted model would have been
 * given: remaining calories, the macro furthest below target, today's meals,
 * the seven-day summary, the streak.
 *
 * That matters for two reasons. The numbers in the answer are the user's own,
 * so the whole feature — context building, validation, persistence, the chat
 * UI — is genuinely exercised without a key. And nobody developing against it
 * is ever shown a figure their data does not support.
 *
 * It is deliberately plainer than a real model would be, and says so once per
 * conversation. It is a stand-in, not an imitation.
 */
class FakeNutritionCoach implements NutritionCoach
{
    private const DISCLOSURE = 'Heads up: NutriLens is running without an AI key, so this reply was '
        .'composed by the built-in offline coach from your own logged data rather than by a language model.';

    public function providerName(): string
    {
        return 'fake';
    }

    public function modelName(): string
    {
        return 'nutrilens-fake-coach';
    }

    public function reply(CoachContext $context, array $history, string $message): array
    {
        $delay = (int) config('ai.providers.fake.delay_ms', 1200);

        if ($delay > 0 && ! app()->runningUnitTests()) {
            usleep($delay * 1000);
        }

        $intent = $this->intent($message);

        $paragraphs = $this->compose($intent, $context);

        // Said once, on the opening reply of a conversation: enough to be
        // honest about what this is without repeating itself every turn.
        if ($history === []) {
            $paragraphs[] = self::DISCLOSURE;
        }

        return [
            'message' => implode("\n\n", array_filter($paragraphs)),
            'suggestions' => $this->suggestions($intent, $context),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Intent                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Keyword scoring rather than a single match: real questions mix topics
     * ("help me hit my protein goal — how much do I still need?"), and the
     * strongest signal has to win.
     *
     * Naming a specific macro is the strongest signal there is, so it is
     * weighted above the generic "how much is left" phrasing that usually
     * accompanies it.
     */
    private function intent(string $message): string
    {
        $text = Str::lower($message);

        $scores = [
            'biggest_meal' => $this->score($text, ['highest-calorie', 'highest calorie', 'biggest meal', 'largest meal', 'most calories']),
            'week' => $this->score($text, ['week', 'weekly', 'consistent', 'consistency', 'trend', 'last 7 days', 'seven days', 'average']),
            'streak' => $this->score($text, ['streak', 'how many days in a row', 'logging habit']),
            'protein' => $this->score($text, ['protein'], weight: 3),
            'balance' => $this->score($text, ['balance', 'balanced', 'macro split', 'improve today', 'better today']),
            'remaining' => $this->score($text, ['remaining', 'left', 'how much more', 'still need', 'over my']),
            // Asking *for food* outranks the "what have I got left" phrasing
            // that usually comes with it — "suggest a meal that fits my
            // remaining macros" is a request for dinner, not for a total.
            'eat_next' => $this->score($text, [
                'what should i eat', 'suggest a meal', 'meal idea', 'what to eat', 'recipe for',
            ], weight: 2) + $this->score($text, [
                'eat', 'dinner', 'lunch', 'breakfast', 'snack', 'suggest', 'recipe', 'cook', 'hungry',
            ]),
            'today' => $this->score($text, ['today', 'how am i doing', 'progress', 'so far']),
        ];

        arsort($scores);

        $best = (string) array_key_first($scores);

        return $scores[$best] > 0 ? $best : 'general';
    }

    /** @param list<string> $needles */
    private function score(string $text, array $needles, int $weight = 1): int
    {
        $score = 0;

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                // A multi-word phrase is a deliberate match, not a stray word.
                $score += (str_word_count($needle) > 1 ? 2 : 1) * $weight;
            }
        }

        return $score;
    }

    /* ------------------------------------------------------------------ */
    /* Composition                                                         */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function compose(string $intent, CoachContext $context): array
    {
        if (! $context->hasGoal()) {
            return $this->withoutTargets($intent, $context);
        }

        return match ($intent) {
            'eat_next' => $this->eatNext($context),
            'protein' => $this->proteinAdvice($context),
            'balance' => $this->balanceAdvice($context),
            'week' => $this->weekReview($context),
            'streak' => $this->streakAnswer($context),
            'biggest_meal' => $this->biggestMeal($context),
            'remaining', 'today' => $this->todayStatus($context),
            default => $this->general($context),
        };
    }

    /**
     * Without targets there is no "remaining", so every answer says what it
     * can from the raw totals and points at setting goals — rather than
     * inventing a target to measure against.
     *
     * @return list<string>
     */
    private function withoutTargets(string $intent, CoachContext $context): array
    {
        $opening = $context->hasMealsToday()
            ? sprintf(
                'You have logged %s today, totalling %s kcal with %sg protein, %sg carbs and %sg fat.',
                $this->plural($context->mealsToday(), 'meal'),
                $this->kcal($context->consumed['calories']),
                $this->grams($context->consumed['protein']),
                $this->grams($context->consumed['carbs']),
                $this->grams($context->consumed['fat']),
            )
            : 'You have not logged anything today yet.';

        $paragraphs = [
            $opening,
            'You have not set daily calorie or macro targets in NutriLens, so I cannot tell you what is '
                .'left or whether a meal fits. Setting them on the Goals screen takes a minute and is what '
                .'turns this into real guidance.',
        ];

        if ($intent === 'eat_next' || $intent === 'protein') {
            $paragraphs[] = 'In the meantime, a plate built around a protein source, a starch and some '
                .'vegetables is a solid default — grilled chicken, fish, eggs, tofu, paneer, beans or '
                .'lentils all work, with rice, potatoes or bread alongside.';
        }

        return $paragraphs;
    }

    /** @return list<string> */
    private function eatNext(CoachContext $context): array
    {
        $remaining = $context->remaining;
        $mealLabel = $this->nextMealLabel($context);

        if ($remaining['calories'] <= 0) {
            return [
                sprintf(
                    'You are %s kcal past your %s kcal target for today, so there is no room left on paper.',
                    $this->kcal(abs($remaining['calories'])),
                    $this->kcal($context->targets['calories']),
                ),
                $remaining['protein'] > 0
                    ? sprintf(
                        'Protein is still %sg short of your %sg target, so if you do eat again, something lean '
                            .'and protein-dense — Greek yoghurt, cottage cheese, a couple of eggs, tofu — costs '
                            .'the fewest calories for the most protein. Estimates, not figures from your log.',
                        $this->grams($remaining['protein']),
                        $this->grams($context->targets['protein']),
                    )
                    : 'Every macro target is met or passed for today, so the useful move is simply to stop here '
                        .'and let tomorrow start clean.',
            ];
        }

        $gapMacro = $context->largestGapMacro();

        $paragraphs = [
            sprintf(
                'You have about %s kcal left today%s, so a %s in that range fits your remaining targets.',
                $this->kcal($remaining['calories']),
                $context->hasMealsToday()
                    ? sprintf(' after the %s you logged', $this->plural($context->mealsToday(), 'meal'))
                    : ' and nothing logged yet',
                $mealLabel,
            ),
        ];

        if ($gapMacro !== null) {
            $paragraphs[] = sprintf(
                '%s is the macro furthest below target — %sg of %sg, so %sg still to go. Building the plate '
                    .'around that is the highest-value thing you can do with the calories you have left.',
                ucfirst($gapMacro),
                $this->grams($context->consumed[$gapMacro]),
                $this->grams($context->targets[$gapMacro]),
                $this->grams(max(0, $remaining[$gapMacro])),
            );
        }

        $paragraphs[] = $this->foodIdeas($gapMacro ?? 'protein');

        return $paragraphs;
    }

    /** @return list<string> */
    private function proteinAdvice(CoachContext $context): array
    {
        $consumed = $context->consumed['protein'];
        $target = $context->targets['protein'];
        $remaining = $context->remaining['protein'];
        $percent = $context->percentOfTarget['protein'];

        if ($remaining <= 0) {
            return [
                sprintf(
                    'Protein is done for today: %sg against your %sg target, which is %d%% of it.',
                    $this->grams($consumed),
                    $this->grams($target),
                    $percent,
                ),
                sprintf(
                    'You still have %s kcal of room, so anything else you eat can be led by what you fancy '
                        .'rather than by macros.',
                    $this->kcal(max(0, $context->remaining['calories'])),
                ),
            ];
        }

        return [
            sprintf(
                'You are at %sg of protein against your %sg target — %d%% of the way there, with %sg to go.',
                $this->grams($consumed),
                $this->grams($target),
                $percent,
                $this->grams($remaining),
            ),
            $context->remaining['calories'] > 0
                ? sprintf(
                    'You have %s kcal left today, which is enough room to close that gap without going over.',
                    $this->kcal($context->remaining['calories']),
                )
                : sprintf(
                    'Calories are already %s kcal past target, so the leanest protein sources are the ones '
                        .'that still work here.',
                    $this->kcal(abs($context->remaining['calories'])),
                ),
            'Dense options, with rough estimates rather than figures from your log: chicken or fish around '
                .'25-30g of protein per 100g, Greek yoghurt or cottage cheese around 10g per 100g, two eggs '
                .'around 12g, firm tofu or tempeh around 15-19g per 100g, lentils around 9g per 100g cooked. '
                .'Spreading it across two smaller servings is usually easier than one large one.',
        ];
    }

    /** @return list<string> */
    private function balanceAdvice(CoachContext $context): array
    {
        $percent = $context->percentOfTarget;

        $macros = ['protein' => $percent['protein'], 'carbs' => $percent['carbs'], 'fat' => $percent['fat']];
        asort($macros);

        $lowest = (string) array_key_first($macros);
        $highest = (string) array_key_last($macros);

        return [
            sprintf(
                'Today sits at %d%% of your calorie target, with protein at %d%%, carbs at %d%% and fat at %d%%.',
                $percent['calories'],
                $percent['protein'],
                $percent['carbs'],
                $percent['fat'],
            ),
            $lowest === $highest
                ? 'The three macros are tracking together, so there is no obvious imbalance to correct — '
                    .'keeping the rest of the day in the same proportions will hold it.'
                : sprintf(
                    '%s is furthest ahead at %d%% and %s furthest behind at %d%%. Leaning the next thing you '
                        .'eat toward %s, and away from %s, is what would even the day out.',
                    ucfirst($highest),
                    $macros[$highest],
                    $lowest,
                    $macros[$lowest],
                    $lowest,
                    $highest,
                ),
            $context->remaining['calories'] > 0
                ? sprintf(
                    'There are %s kcal left to work with.',
                    $this->kcal($context->remaining['calories']),
                )
                : sprintf(
                    'Calories are %s kcal over target, so this is about the shape of tomorrow more than tonight.',
                    $this->kcal(abs($context->remaining['calories'])),
                ),
        ];
    }

    /** @return list<string> */
    private function weekReview(CoachContext $context): array
    {
        $week = $context->weekSummary;
        $averages = $week['average_per_logged_day'];

        if ($week['days_logged'] === 0) {
            return [
                'There is nothing logged in the last seven days, so there is no week to read yet.',
                'Log a few days and I can tell you how consistent you were, how your averages sat against '
                    .'your targets, and where the gaps were.',
            ];
        }

        $paragraphs = [
            sprintf(
                'Over the last seven days you logged %s across %s. On the days you logged, you averaged '
                    .'%s kcal, %sg protein, %sg carbs and %sg fat.',
                $this->plural($week['meals_logged'], 'meal'),
                $this->plural($week['days_logged'], 'day'),
                $this->kcal($averages['calories']),
                $this->grams($averages['protein']),
                $this->grams($averages['carbs']),
                $this->grams($averages['fat']),
            ),
        ];

        if ($week['percent_of_logged_days_close_to_target'] !== null) {
            $paragraphs[] = $week['days_logged'] === 1
                ? sprintf(
                    'That day %s within %d%% of your %s kcal target.',
                    $week['days_close_to_calorie_target'] === 1 ? 'landed' : 'did not land',
                    $week['target_tolerance_percent'],
                    $this->kcal($context->targets['calories']),
                )
                : sprintf(
                    '%d of those %d days landed within %d%% of your %s kcal target — %d%% of the days you logged.',
                    $week['days_close_to_calorie_target'],
                    $week['days_logged'],
                    $week['target_tolerance_percent'],
                    $this->kcal($context->targets['calories']),
                    $week['percent_of_logged_days_close_to_target'],
                );
        }

        $proteinGap = round($context->targets['protein'] - $averages['protein'], 1);

        if ($proteinGap > 5) {
            $paragraphs[] = sprintf(
                'The clearest gap is protein: an average of %sg against a %sg target, so about %sg short on '
                    .'a typical day. That is usually a portion-size problem rather than a whole extra meal.',
                $this->grams($averages['protein']),
                $this->grams($context->targets['protein']),
                $this->grams($proteinGap),
            );
        } elseif ($week['days_logged'] < 7) {
            $paragraphs[] = sprintf(
                'You logged %s of the last 7, so the averages describe those days rather than the whole week. '
                    .'Filling the gaps is what makes them trustworthy.',
                $this->plural($week['days_logged'], 'day'),
            );
        }

        return $paragraphs;
    }

    /** @return list<string> */
    private function streakAnswer(CoachContext $context): array
    {
        $streak = $context->streak;

        if ($streak['current_days'] === 0) {
            return [
                $streak['total_days_logged'] === 0
                    ? 'You have not logged any days yet, so there is no streak running.'
                    : sprintf(
                        'Your streak is at zero right now. Your longest run so far is %s, across %s logged '
                            .'in total.',
                        $this->plural($streak['longest_days'], 'day'),
                        $this->plural($streak['total_days_logged'], 'day'),
                    ),
                'Logging one meal today is enough to start a new one.',
            ];
        }

        return [
            sprintf(
                'You are on a %s streak, against a personal best of %s and %s logged in total.',
                $this->plural($streak['current_days'], 'day'),
                $this->plural($streak['longest_days'], 'day'),
                $this->plural($streak['total_days_logged'], 'day'),
            ),
            $streak['logged_today']
                ? 'Today is already logged, so the streak is safe.'
                : 'Nothing is logged today yet, so one meal keeps it alive.',
        ];
    }

    /** @return list<string> */
    private function biggestMeal(CoachContext $context): array
    {
        $meal = $context->largestRecentMeal;

        if ($meal === null) {
            return ['There are no meals logged in the last seven days, so there is no biggest one to report.'];
        }

        return [
            sprintf(
                'Your highest-calorie meal in the last seven days was %s on %s, at %s kcal — %sg protein, '
                    .'%sg carbs and %sg fat.',
                $meal['name'],
                $meal['date'],
                $this->kcal($meal['calories']),
                $this->grams($meal['protein']),
                $this->grams($meal['carbs']),
                $this->grams($meal['fat']),
            ),
            sprintf(
                'For scale, your daily calorie target is %s kcal.',
                $this->kcal($context->targets['calories']),
            ),
        ];
    }

    /** @return list<string> */
    private function todayStatus(CoachContext $context): array
    {
        $remaining = $context->remaining;

        $opening = $context->hasMealsToday()
            ? sprintf(
                'You have logged %s today, totalling %s kcal of your %s kcal target.',
                $this->plural($context->mealsToday(), 'meal'),
                $this->kcal($context->consumed['calories']),
                $this->kcal($context->targets['calories']),
            )
            : sprintf(
                'Nothing is logged today yet, so the full %s kcal target is still open.',
                $this->kcal($context->targets['calories']),
            );

        return [
            $opening,
            sprintf(
                'Remaining: %s kcal, %sg protein, %sg carbs and %sg fat.',
                $this->kcal($remaining['calories']),
                $this->grams($remaining['protein']),
                $this->grams($remaining['carbs']),
                $this->grams($remaining['fat']),
            ),
            $remaining['calories'] < 0
                ? 'You are over on calories for today. Worth noting, not worth panicking about — one day is '
                    .'a data point, not a trend.'
                : sprintf(
                    'That is %d%% of your calories and %d%% of your protein done.',
                    $context->percentOfTarget['calories'],
                    $context->percentOfTarget['protein'],
                ),
        ];
    }

    /** @return list<string> */
    private function general(CoachContext $context): array
    {
        return [
            ...$this->todayStatus($context),
            'Ask me what to eat next, how to close your protein gap, how your last seven days went, or what '
                .'your biggest meal was — I answer from your logged data rather than in general terms.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Suggestions                                                         */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function suggestions(string $intent, CoachContext $context): array
    {
        if (! $context->hasGoal()) {
            return ['How do I set my targets?', 'Give me a balanced meal idea'];
        }

        return match ($intent) {
            'eat_next', 'general' => ['How much protein do I still need?', 'How did my week go?'],
            'protein' => ['Suggest a high-protein dinner', 'Why do I keep missing protein?'],
            'balance' => ['What should I eat next?', 'How did my week go?'],
            'week' => ['How consistent was I?', 'What should I eat next?'],
            'streak' => ['How is today tracking?', 'What should I eat next?'],
            'biggest_meal' => ['How did my week go?', 'What should I eat next?'],
            default => ['What should I eat next?', 'How much protein do I still need?'],
        };
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The meal the clock and today's log suggest is next. Deterministic, so
     * the offline coach never suggests dinner at 9am or a second breakfast.
     */
    private function nextMealLabel(CoachContext $context): string
    {
        $logged = array_column($context->todayMeals, 'meal_type');

        $byClock = match ($context->partOfDay) {
            'morning' => 'breakfast',
            'midday' => 'lunch',
            'afternoon' => 'snack',
            'evening' => 'dinner',
            default => 'snack',
        };

        return in_array($byClock, $logged, true) ? 'follow-up meal or snack' : $byClock;
    }

    /** Broad, diet-agnostic ideas, explicitly framed as estimates. */
    private function foodIdeas(string $macro): string
    {
        return match ($macro) {
            'carbs' => 'Carb-forward options that still carry some protein: a rice or noodle bowl with beans '
                .'or chicken, wholegrain pasta with lentil ragu, or a jacket potato with cottage cheese. '
                .'Rough estimates, not figures from your log.',
            'fat' => 'Fat-forward options: salmon or mackerel, eggs cooked in olive oil, avocado on toast, or '
                .'a handful of nuts alongside whatever else you are having. Rough estimates, not figures from '
                .'your log.',
            default => 'Protein-forward options that suit most diets: grilled chicken or fish, paneer or tofu, '
                .'eggs, Greek yoghurt, or a lentil and bean bowl — each with a moderate carbohydrate source '
                .'such as rice, potatoes or bread, and some vegetables. Expect roughly 30-40g of protein from '
                .'a normal portion of any of those, as an estimate rather than a figure from your log.',
        };
    }

    private function kcal(int|float $value): string
    {
        return number_format(round($value));
    }

    /** Trim a trailing ".0" so the prose reads as prose. */
    private function grams(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
