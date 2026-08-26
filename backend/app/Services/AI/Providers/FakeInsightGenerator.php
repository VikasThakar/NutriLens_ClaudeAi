<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\NutritionInsightGenerator;
use App\Services\AI\Data\WeeklyNutritionSummary;

/**
 * Offline driver — AI_PROVIDER=fake.
 *
 * Writes the weekly review from the user's actual aggregates rather than
 * returning canned prose. That matters for two reasons: the numbers in the
 * output are real, so the whole feature (including the traceable-number check
 * in WeeklyInsightService) is genuinely exercised without a key; and nobody
 * developing against it is ever shown a figure their data does not support.
 *
 * It is deliberately plainer than a real model would be. It is a stand-in, not
 * an imitation.
 */
class FakeInsightGenerator implements NutritionInsightGenerator
{
    public function providerName(): string
    {
        return 'fake';
    }

    public function modelName(): string
    {
        return 'nutrilens-fake-insights';
    }

    public function generate(WeeklyNutritionSummary $summary): array
    {
        $delay = (int) config('ai.providers.fake.delay_ms', 1200);

        if ($delay > 0 && ! app()->runningUnitTests()) {
            usleep($delay * 1000);
        }

        return [
            'headline' => $this->headline($summary),
            'summary' => $this->summary($summary),
            'observations' => $this->observations($summary),
            'suggestions' => $this->suggestions($summary),
        ];
    }

    private function headline(WeeklyNutritionSummary $summary): string
    {
        if ($summary->hasComparison()) {
            $change = $summary->averages['calories'] - $summary->previous['averages']['calories'];

            if (abs($change) < 50) {
                return 'A steady week';
            }

            return $change > 0 ? 'Calories up on last week' : 'Calories down on last week';
        }

        return $summary->daysLogged >= 6 ? 'A well-logged week' : 'Your week so far';
    }

    private function summary(WeeklyNutritionSummary $summary): string
    {
        $averages = $summary->averages;

        $sentences = [sprintf(
            'You logged %d of 7 days this week, %d meals in total, averaging %s kcal and %sg of protein on the days you logged.',
            $summary->daysLogged,
            $summary->mealsLogged,
            number_format($averages['calories']),
            $this->grams($averages['protein']),
        )];

        if ($summary->hasComparison()) {
            $previous = $summary->previous['averages'];
            $proteinChange = round($averages['protein'] - $previous['protein'], 1);

            $sentences[] = sprintf(
                'That is %sg of protein against %sg the week before, and %s kcal against %s.',
                $this->grams($averages['protein']),
                $this->grams($previous['protein']),
                number_format($averages['calories']),
                number_format($previous['calories']),
            );

            if (abs($proteinChange) < 5) {
                $sentences[] = 'Protein held roughly steady between the two weeks.';
            }
        } else {
            $sentences[] = 'There is not enough logged data in the previous week to compare against yet, so this is a read on this week alone.';
        }

        return implode(' ', $sentences);
    }

    /** @return list<string> */
    private function observations(WeeklyNutritionSummary $summary): array
    {
        $observations = [];

        if ($summary->targets !== null) {
            $observations[] = sprintf(
                '%d of your %d logged days landed within %d%% of your %s kcal target.',
                $summary->daysCloseToTarget,
                $summary->daysLogged,
                $summary->tolerancePercent,
                number_format($summary->targets['calories']),
            );
        }

        if ($summary->weekdayAverageCalories !== null && $summary->weekendAverageCalories !== null) {
            $observations[] = sprintf(
                'Weekdays averaged %s kcal and weekend days %s kcal.',
                number_format($summary->weekdayAverageCalories),
                number_format($summary->weekendAverageCalories),
            );
        }

        if ($summary->calorieSpread !== null) {
            $observations[] = sprintf(
                'Your daily calories varied by about %s kcal either side of the average.',
                number_format($summary->calorieSpread),
            );
        }

        $observations[] = sprintf(
            'Carbohydrates averaged %sg and fat %sg across the logged days.',
            $this->grams($summary->averages['carbs']),
            $this->grams($summary->averages['fat']),
        );

        return array_slice($observations, 0, 4);
    }

    /** @return list<string> */
    private function suggestions(WeeklyNutritionSummary $summary): array
    {
        $suggestions = [];

        if ($summary->daysLogged < 7) {
            $suggestions[] = sprintf(
                'You logged %d of 7 days. Filling the gaps makes the weekly averages more reliable.',
                $summary->daysLogged,
            );
        }

        if ($summary->targets !== null) {
            $gap = $summary->targets['protein'] - $summary->averages['protein'];

            if ($gap > 10) {
                $suggestions[] = sprintf(
                    'Protein came in %sg under your target on an average day.',
                    $this->grams(round($gap, 1)),
                );
            }
        }

        return array_slice($suggestions, 0, 3);
    }

    /** Trim a trailing ".0" so the prose reads as prose. */
    private function grams(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
