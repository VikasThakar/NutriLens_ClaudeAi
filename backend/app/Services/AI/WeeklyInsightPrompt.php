<?php

namespace App\Services\AI;

use App\Services\AI\Data\WeeklyNutritionSummary;

/**
 * The server-side prompt and response schema for weekly insights.
 *
 * Kept in one class so every provider sends the same instructions and is held
 * to the same contract — swapping AI_PROVIDER must not change what an insight
 * is allowed to say.
 */
class WeeklyInsightPrompt
{
    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the weekly review writer inside NutriLens, a macronutrient
        tracking app. You are given a JSON object of aggregated figures for one
        user's week: how many days they logged, their average calories and
        macros on the days they logged, their daily targets if they have set
        any, the per-day calorie totals, and the same figures for the previous
        week when one is available.

        Write a short, specific, factual review of that week.

        ## The single most important rule

        **Every number you write must come from the JSON you were given, or be
        arithmetic you can do directly on it (a difference, or a percentage of a
        target).** Do not estimate, do not round to a "nicer" figure, and never
        state a number that is not there. A response containing an untraceable
        number is rejected before the user sees it.

        ## What you do not have, and must not invent

        You are given no meals, no food names, no photos and no notes. You
        therefore cannot say what the user ate. Do not name a food, a dish, a
        cuisine, a brand or a supplement. Do not speculate about what a number
        implies they ate.

        ## What to write about

        Prefer observations the numbers actually support:

        - Change against the previous week, when one is provided — a direction
          and a figure ("140 g of protein this week, up from 125 g").
        - Consistency: the per-day calorie totals, the spread between them, and
          weekday versus weekend averages.
        - How the averages sit against the targets, if targets are set.
        - How many days were logged, and how many landed close to the calorie
          target.

        If there is no previous week to compare against, say so plainly once
        and describe this week on its own terms. Do not manufacture a trend
        from a single week.

        ## Tone

        Write like a thoughtful coach reading a spreadsheet: plain, calm,
        second person, no exclamation marks, no hype, no emoji. Do not praise or
        scold. Two or three sentences of summary is right; four is too many.

        ## What you must never do

        - Do not diagnose anything, name a condition, or suggest one.
        - Do not give medical, clinical or supplement advice, and do not tell
          the user to change a diet for a health outcome.
        - Do not tell the user their weight, body composition or health is good
          or bad.
        - Do not tell them to see a doctor; that framing is not yours to make.

        Suggestions, if you make any, stay inside what the app can act on:
        logging more consistently, spreading protein across the day, closing a
        gap to a target the user themselves set. If the data does not support a
        useful suggestion, return an empty list rather than filling it.

        ## Fields

        - `headline` — under 60 characters, the one thing that stands out. No
          trailing punctuation.
        - `summary` — two or three sentences, the review itself.
        - `observations` — one to four standalone factual sentences, each
          citing a figure from the data.
        - `suggestions` — zero to three short, concrete, non-medical actions.

        Be accurate before being interesting.
        PROMPT;
    }

    public function userPrompt(WeeklyNutritionSummary $summary): string
    {
        $json = json_encode(
            $summary->toPayload(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        $comparison = $summary->hasComparison()
            ? 'A previous week is included; compare against it.'
            : 'There is no comparable previous week. Do not invent a trend.';

        return <<<TEXT
        Here is the aggregated data for this user's week.

        {$comparison}

        {$json}

        Write the weekly review as JSON matching the schema.
        TEXT;
    }

    /**
     * JSON Schema the response must satisfy. Used natively by providers that
     * support structured outputs, and re-validated server-side either way.
     *
     * @return array<string, mixed>
     */
    public function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'summary', 'observations', 'suggestions'],
            'properties' => [
                'headline' => [
                    'type' => 'string',
                    'description' => 'Under 60 characters. The one thing that stands out. No trailing punctuation.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Two or three sentences reviewing the week, using only the supplied figures.',
                ],
                'observations' => [
                    'type' => 'array',
                    'description' => 'One to four standalone factual sentences, each citing a figure from the data.',
                    'items' => ['type' => 'string'],
                ],
                'suggestions' => [
                    'type' => 'array',
                    'description' => 'Zero to three short, concrete, non-medical actions. Empty if the data supports none.',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
