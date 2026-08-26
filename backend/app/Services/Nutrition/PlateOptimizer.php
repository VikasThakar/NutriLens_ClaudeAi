<?php

namespace App\Services\Nutrition;

use App\Services\Nutrition\Data\PlateItem;
use App\Services\Nutrition\Data\PlateMeal;
use App\Services\Nutrition\Data\PlateScore;
use Illuminate\Support\Str;

/**
 * Generates the three Smart Plate optimizations.
 *
 * The method is the same for all three, and it is what keeps the suggestions
 * honest: **propose, simulate, score, keep the best.**
 *
 *  1. Build a handful of concrete candidate plates — this item at 150 g instead
 *     of 250 g, that plate with 90 g of tuna added.
 *  2. Simulate each one through PlateItem::withPortion, which reproduces the
 *     frontend's scaling and lock rules exactly.
 *  3. Score each candidate with the same MealFitScore the current plate was
 *     scored with.
 *  4. Keep the best, and only if it actually beats what the user already has.
 *
 * Nothing here is a hand-written rule about which food to cut. "Reduce the rice
 * rather than the chicken" is not coded anywhere — it falls out of the scorer,
 * because cutting the chicken costs protein and the score notices. That means
 * the advice stays consistent with the score the user is looking at, and a
 * suggestion that would not help is never shown at all.
 */
class PlateOptimizer
{
    /**
     * A suggestion has to move the score by at least this much to be worth
     * showing. Set above a rounding error deliberately: "reduce the rice from
     * 300 g to 290 g" for a tenth of a point is noise dressed up as advice.
     */
    private const MIN_IMPROVEMENT = 0.2;

    /**
     * Calories have to be past what is left by at least this much — 30 kcal, or
     * 2% of the day's target, whichever is larger — before trimming a portion
     * is worth suggesting. Being 8 kcal over is not a problem to solve.
     */
    private const MIN_MATERIAL_OVERSHOOT = 30.0;

    private const MIN_MATERIAL_OVERSHOOT_SHARE = 0.02;

    /** Below this protein shortfall, there is nothing worth boosting. */
    private const MIN_PROTEIN_DEFICIT = 5.0;

    /**
     * The most a suggestion will grow a portion. "Have 60% more of this" is a
     * realistic ask; doubling a plate of rice is not, and when the shortfall is
     * bigger than that, adding a protein food is the better answer anyway.
     */
    private const MAX_PORTION_INCREASE = 1.6;

    /** No suggestion cuts a portion below this share of itself. */
    private const MIN_PORTION_REMAINING = 0.4;

    /** Bounds on an added food, so nobody is told to eat 400 g of tofu. */
    private const MIN_ADDED_GRAMS = 20.0;

    private const MAX_ADDED_GRAMS = 250.0;

    /**
     * Foods Smart Plate may suggest adding, keyed into FoodNutritionTable so
     * the macros come from the same reference the rest of the product uses.
     *
     * Chosen for coverage rather than length: something for every diet, and
     * nothing that would be strange to add to a plate. `piece_grams` lets a
     * gram figure be described in the units people actually think in.
     *
     * @var list<array<string, mixed>>
     */
    private const ADDITIONS = [
        ['key' => 'grilled chicken breast', 'name' => 'Grilled chicken breast', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'tuna', 'name' => 'Tuna', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'white fish', 'name' => 'White fish', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'prawn', 'name' => 'Prawns', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'egg', 'name' => 'Boiled eggs', 'unit' => 'g', 'role' => 'protein', 'piece_grams' => 50.0, 'piece_label' => 'egg'],
        ['key' => 'greek yoghurt', 'name' => 'Greek yoghurt', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'cottage cheese', 'name' => 'Cottage cheese', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'paneer', 'name' => 'Paneer', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'tofu', 'name' => 'Firm tofu', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'lentil', 'name' => 'Cooked lentils', 'unit' => 'g', 'role' => 'protein'],
        ['key' => 'protein shake', 'name' => 'Protein shake', 'unit' => 'ml', 'role' => 'protein'],
        ['key' => 'broccoli', 'name' => 'Steamed broccoli', 'unit' => 'g', 'role' => 'volume'],
        ['key' => 'salad', 'name' => 'Green salad', 'unit' => 'g', 'role' => 'volume'],
    ];

    public function __construct(
        private readonly MealFitScore $scorer,
        private readonly FoodNutritionTable $foods,
    ) {
    }

    /**
     * The three optimizations, always in the same order and always all three —
     * one that cannot help says so, rather than vanishing and leaving the user
     * wondering whether it was considered.
     *
     * @param  array{calories:int, protein:int, carbs:int, fat:int}  $targets
     * @param  array{calories:int, protein:float, carbs:float, fat:float}  $remaining
     * @return list<array<string, mixed>>
     */
    public function optimize(
        PlateMeal $meal,
        array $targets,
        array $remaining,
        PlateScore $current,
    ): array {
        return [
            $this->boostProtein($meal, $targets, $remaining, $current),
            $this->reduceCalories($meal, $targets, $remaining, $current),
            $this->balanceMeal($meal, $targets, $remaining, $current),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Option 1 — Boost Protein                                            */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function boostProtein(
        PlateMeal $meal,
        array $targets,
        array $remaining,
        PlateScore $current,
    ): array {
        $protein = $current->component('protein');
        $deficit = max(0.0, (float) $protein['expected'] - (float) $protein['meal']);

        if (($protein['target_already_met'] ?? false) === true) {
            return $this->unavailable(
                'boost_protein',
                'Your protein target for today is already met, so there is nothing to boost.',
            );
        }

        if ($deficit < self::MIN_PROTEIN_DEFICIT) {
            return $this->unavailable(
                'boost_protein',
                'This meal is already carrying the protein a meal its size should. Nothing to add.',
            );
        }

        $candidates = [
            ...$this->scaleUpProteinCandidates($meal, $deficit),
            ...$this->addProteinCandidates($meal, $deficit),
        ];

        $best = $this->best($candidates, $targets, $remaining, $current);

        if ($best === null) {
            $over = (float) $current->component('calories')['over_by'];

            return $this->unavailable(
                'boost_protein',
                $over >= $this->materialOvershoot($targets)
                    ? sprintf(
                        'This meal is already %s kcal past what is left today, so adding to it would not '
                            .'help. Trim it first, or make the next meal the protein-led one.',
                        number_format($over),
                    )
                    : 'There is no way to add protein here without making the rest of your day worse. '
                        .'A protein-led meal later would do more.',
            );
        }

        $alternatives = $this->alternativeNames($candidates, $best, $targets, $remaining);

        return $this->describe('boost_protein', $best, $meal, $current, sprintf(
            'You are about %sg of protein short of what a meal this size should carry today.%s',
            $this->grams($deficit),
            $alternatives === '' ? '' : ' '.$alternatives,
        ));
    }

    /**
     * Grow an existing protein-carrying item. Preferred over adding something
     * new: the user chose this plate, and a bigger piece of the chicken already
     * on it is a smaller ask than a new ingredient.
     *
     * @return list<array<string, mixed>>
     */
    private function scaleUpProteinCandidates(PlateMeal $meal, float $deficit): array
    {
        $candidates = [];

        foreach ($meal->items as $index => $item) {
            if (! $item->isScalable() || $item->macroIsLocked('protein')) {
                continue;
            }

            /*
             * Calories have to be free to move as well.
             *
             * With them pinned by hand, growing a portion looks like free
             * protein to the scorer — and it would happily propose 880 g of
             * rice, because the calories it costs are invisible to it. Eating
             * more of something does not cost nothing, so an increase is only
             * offered where the price is actually counted.
             */
            if ($item->macroIsLocked('calories')) {
                continue;
            }

            $proteinPerUnit = $item->basePerUnit('protein');

            if ($proteinPerUnit === null || $proteinPerUnit <= 0) {
                continue;
            }

            foreach ([1.0, 0.7, 0.45] as $fraction) {
                $wanted = $item->portionAmount + (($deficit * $fraction) / $proteinPerUnit);
                $capped = min($wanted, $item->portionAmount * self::MAX_PORTION_INCREASE);
                $portion = $this->roundPortion($capped, $item->portionUnit);

                if ($portion <= $item->portionAmount) {
                    continue;
                }

                $candidates[] = $this->portionCandidate($meal, $index, $item, $portion, prefersExisting: true);
            }
        }

        return $candidates;
    }

    /**
     * Add a protein food, sized to close the gap. Every option in the table is
     * tried; the scorer picks, so a plate that is already fat-heavy gets a lean
     * suggestion without that rule being written anywhere.
     *
     * @return list<array<string, mixed>>
     */
    private function addProteinCandidates(PlateMeal $meal, float $deficit): array
    {
        $candidates = [];

        foreach (self::ADDITIONS as $food) {
            if ($food['role'] !== 'protein') {
                continue;
            }

            $per100 = $this->foods->perHundred($food['key']);
            $proteinPer100 = $per100[1];

            if ($proteinPer100 <= 0) {
                continue;
            }

            foreach ([1.0, 0.65] as $fraction) {
                $grams = ($deficit * $fraction) * 100 / $proteinPer100;
                $grams = max(self::MIN_ADDED_GRAMS, min(self::MAX_ADDED_GRAMS, $grams));
                $portion = $this->roundPortion($grams, $food['unit']);

                $candidates[] = $this->additionCandidate($meal, $food, $portion);
            }
        }

        return $candidates;
    }

    /* ------------------------------------------------------------------ */
    /* Option 2 — Reduce Calories                                          */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function reduceCalories(
        PlateMeal $meal,
        array $targets,
        array $remaining,
        PlateScore $current,
    ): array {
        $calories = $current->component('calories');
        $over = (float) $calories['over_by'];
        $material = $this->materialOvershoot($targets);

        if ($over <= 0) {
            return $this->unavailable(
                'reduce_calories',
                sprintf(
                    'This meal already fits inside the %s kcal you have left, so there is nothing to trim.',
                    number_format(max(0, (float) $calories['headroom'])),
                ),
            );
        }

        if ($over < $material) {
            return $this->unavailable(
                'reduce_calories',
                sprintf(
                    'This meal is only %s kcal past what is left — close enough that shaving a portion is not '
                        .'worth it.',
                    number_format($over),
                ),
            );
        }

        // Aim a little past the overshoot so the result is comfortably inside
        // the budget rather than balanced on the line.
        $needed = $over + max(10.0, $over * 0.05);
        $candidates = $this->reductionCandidates($meal, 'calories', $needed);

        if ($candidates === []) {
            return $this->unavailable('reduce_calories', $this->noScalingReason($meal));
        }

        $best = $this->best($candidates, $targets, $remaining, $current);

        if ($best === null) {
            return $this->unavailable(
                'reduce_calories',
                'Every way of trimming this plate costs more in protein than it saves in calories. '
                    .'Leaving it as it is and eating lighter later is the better trade.',
            );
        }

        return $this->describe('reduce_calories', $best, $meal, $current, sprintf(
            'This meal is %s kcal past what is left of today, and the cut below costs the least protein.',
            number_format($over),
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Option 3 — Balance Meal                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The only option allowed to make two changes at once — trimming what is
     * over *and* topping up what is short — which is usually what actually
     * fixes a day.
     *
     * @return array<string, mixed>
     */
    private function balanceMeal(
        PlateMeal $meal,
        array $targets,
        array $remaining,
        PlateScore $current,
    ): array {
        $protein = $current->component('protein');
        $deficit = ($protein['target_already_met'] ?? false)
            ? 0.0
            : max(0.0, (float) $protein['expected'] - (float) $protein['meal']);

        // Trim whichever macro is furthest past what is left, measured against
        // its own target so grams of fat and kcal are comparable.
        $reductions = [];

        foreach (['calories', 'carbs', 'fat'] as $macro) {
            $over = (float) $current->component($macro)['over_by'];
            $target = max(1, (int) $targets[$macro]);

            if ($over <= 0) {
                continue;
            }

            $reductions[$macro] = $over / $target;
        }

        arsort($reductions);

        $reductionCandidates = [];

        foreach (array_slice(array_keys($reductions), 0, 2) as $macro) {
            $over = (float) $current->component($macro)['over_by'];
            $reductionCandidates = [
                ...$reductionCandidates,
                ...$this->reductionCandidates($meal, $macro, $over + max(5.0, $over * 0.05)),
            ];
        }

        $proteinCandidates = $deficit >= self::MIN_PROTEIN_DEFICIT
            ? [
                ...$this->scaleUpProteinCandidates($meal, $deficit),
                ...$this->addProteinCandidates($meal, $deficit),
            ]
            : [];

        $candidates = $this->combine(
            $meal,
            array_slice($reductionCandidates, 0, 6),
            array_slice($proteinCandidates, 0, 8),
        );

        // Adding volume is the third lever: it does not fix a macro, but a
        // trimmed plate with vegetables back on it is a meal someone will
        // actually eat.
        foreach (self::ADDITIONS as $food) {
            if ($food['role'] !== 'volume') {
                continue;
            }

            $candidates[] = $this->additionCandidate($meal, $food, 100.0);
        }

        $best = $this->best($candidates, $targets, $remaining, $current);

        if ($best === null) {
            return $this->unavailable(
                'balance_meal',
                $reductions === [] && $deficit < self::MIN_PROTEIN_DEFICIT
                    ? 'This meal is already well balanced against what you have left today. No changes needed.'
                    : 'No combination of portion changes improves the balance here. Adjusting the items by hand, '
                        .'or eating differently later, would do more.',
            );
        }

        // No lead sentence here: the improvement summary the describer appends
        // *is* the point of this option, and prefacing it with the shortfall
        // that prompted the search reads oddly when the winning change turns
        // out to fix something else.
        return $this->describe('balance_meal', $best, $meal, $current, '');
    }

    /**
     * Every reduction paired with every protein top-up, plus each on its own.
     * Bounded by the slices the caller passes in, so this stays a few dozen
     * cheap arithmetic simulations rather than a combinatorial explosion.
     *
     * @param  list<array<string, mixed>>  $reductions
     * @param  list<array<string, mixed>>  $additions
     * @return list<array<string, mixed>>
     */
    private function combine(PlateMeal $meal, array $reductions, array $additions): array
    {
        $candidates = [...$reductions, ...$additions];

        foreach ($reductions as $reduction) {
            foreach ($additions as $addition) {
                $merged = $this->merge($meal, [$reduction, $addition]);

                if ($merged !== null) {
                    $candidates[] = $merged;
                }
            }
        }

        return $candidates;
    }

    /**
     * Replay several candidates' changes onto one plate.
     *
     * Two changes to the same item would fight each other, so that pair is
     * dropped rather than silently letting the last one win.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function merge(PlateMeal $meal, array $candidates): ?array
    {
        $plate = $meal;
        $changes = [];
        $notes = [];
        $touched = [];

        foreach ($candidates as $candidate) {
            foreach ($candidate['changes'] as $change) {
                if ($change['action'] === 'set_portion') {
                    if (isset($touched[$change['item_index']])) {
                        return null;
                    }

                    $touched[$change['item_index']] = true;
                    $item = $plate->items[$change['item_index']];
                    $plate = $plate->withItem(
                        $change['item_index'],
                        $item->withPortion((float) $change['to_portion']),
                    );
                } else {
                    $plate = $plate->withAddedItem($this->itemFromChange($change));
                }

                $changes[] = $change;
            }

            $notes = [...$notes, ...$candidate['notes']];
        }

        return [
            'plate' => $plate,
            'changes' => $changes,
            'notes' => array_values(array_unique($notes)),
            'prefers_existing' => false,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Candidate construction                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Portion changes that would remove roughly `$needed` of `$macro`.
     *
     * The item's macro has to be unlocked: a portion change cannot move a value
     * the user typed by hand, so proposing one would be proposing nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function reductionCandidates(PlateMeal $meal, string $macro, float $needed): array
    {
        $candidates = [];

        foreach ($meal->items as $index => $item) {
            if (! $item->isScalable() || $item->macroIsLocked($macro)) {
                continue;
            }

            $perUnit = $item->basePerUnit($macro);

            if ($perUnit === null || $perUnit <= 0) {
                continue;
            }

            foreach ([1.0, 0.65] as $fraction) {
                $wanted = $item->portionAmount - (($needed * $fraction) / $perUnit);
                $floor = $item->portionAmount * self::MIN_PORTION_REMAINING;
                $portion = $this->roundPortion(max($wanted, $floor), $item->portionUnit);

                if ($portion <= 0 || $portion >= $item->portionAmount) {
                    continue;
                }

                $candidates[] = $this->portionCandidate($meal, $index, $item, $portion, prefersExisting: true);
            }
        }

        return $candidates;
    }

    /** @return array<string, mixed> */
    private function portionCandidate(
        PlateMeal $meal,
        int $index,
        PlateItem $item,
        float $portion,
        bool $prefersExisting,
    ): array {
        return [
            'plate' => $meal->withItem($index, $item->withPortion($portion)),
            'changes' => [[
                'action' => 'set_portion',
                'item_index' => $index,
                'item_name' => $item->name,
                'from_portion' => $this->trim($item->portionAmount),
                'to_portion' => $this->trim($portion),
                'portion_unit' => $item->portionUnit,
            ]],
            'notes' => $this->lockNotes($item),
            'prefers_existing' => $prefersExisting,
        ];
    }

    /**
     * @param  array<string, mixed>  $food
     * @return array<string, mixed>
     */
    private function additionCandidate(PlateMeal $meal, array $food, float $portion): array
    {
        $per100 = $this->foods->perHundred($food['key']);
        $scale = $portion / 100;

        $macros = [
            'calories' => (int) round($per100[0] * $scale),
            'protein' => round($per100[1] * $scale, 1),
            'carbs' => round($per100[2] * $scale, 1),
            'fat' => round($per100[3] * $scale, 1),
        ];

        $item = PlateItem::suggested(
            name: $food['name'],
            portionAmount: $portion,
            portionUnit: $food['unit'],
            calories: $macros['calories'],
            protein: $macros['protein'],
            carbs: $macros['carbs'],
            fat: $macros['fat'],
        );

        return [
            'plate' => $meal->withAddedItem($item),
            'changes' => [[
                'action' => 'add_item',
                'item_name' => $food['name'],
                'portion_amount' => $this->trim($portion),
                'portion_unit' => $food['unit'],
                'portion_hint' => $this->portionHint($food, $portion),
                'macros' => $macros,
            ]],
            'notes' => [],
            'prefers_existing' => false,
        ];
    }

    private function itemFromChange(array $change): PlateItem
    {
        return PlateItem::suggested(
            name: $change['item_name'],
            portionAmount: (float) $change['portion_amount'],
            portionUnit: $change['portion_unit'],
            calories: (int) $change['macros']['calories'],
            protein: (float) $change['macros']['protein'],
            carbs: (float) $change['macros']['carbs'],
            fat: (float) $change['macros']['fat'],
        );
    }

    /* ------------------------------------------------------------------ */
    /* Selection and description                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The best candidate, or null when none of them beats the plate the user
     * already has. Ties go to a change to an existing item.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function best(
        array $candidates,
        array $targets,
        array $remaining,
        PlateScore $current,
    ): ?array {
        $best = null;

        $currentCalories = (float) $current->component('calories')['meal'];

        /*
         * A hard constraint the score cannot override: never suggest adding
         * calories to a meal that is already materially past what is left.
         *
         * The scorer will happily trade a ruined calorie row for a perfect
         * protein one — "add 85 g of chicken" to a meal already 520 kcal over
         * comes out ahead on points. It is still bad advice, and no amount of
         * protein makes eating more of an over-budget meal the right move.
         */
        $overBudget = (float) $current->component('calories')['over_by']
            >= $this->materialOvershoot($targets);

        foreach ($candidates as $candidate) {
            $totals = $candidate['plate']->totals();

            if ($overBudget && $totals['calories'] > $currentCalories) {
                continue;
            }

            $score = $this->scorer->evaluate($totals, $targets, $remaining);

            if ($score->score < $current->score + self::MIN_IMPROVEMENT) {
                continue;
            }

            $candidate['score'] = $score;

            if ($best === null || $this->beats($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $incumbent
     */
    private function beats(array $candidate, array $incumbent): bool
    {
        $difference = $candidate['score']->score - $incumbent['score']->score;

        if (abs($difference) > 0.15) {
            return $difference > 0;
        }

        // Within a rounding error of each other: prefer changing what is
        // already on the plate, then prefer the simpler change.
        if ($candidate['prefers_existing'] !== $incumbent['prefers_existing']) {
            return $candidate['prefers_existing'] === true;
        }

        return count($candidate['changes']) < count($incumbent['changes']);
    }

    /**
     * What the chosen change actually does to the score, component by
     * component.
     *
     * Derived from the candidate that won rather than from the shortfall that
     * prompted the search — those are not always the same thing. Cutting an
     * oversized portion of rice can fix the protein row too, because a smaller
     * meal is expected to carry less of it, and the copy has to say what
     * happened rather than what was hoped for.
     *
     * Anything the change makes *worse* is named as well. A suggestion that
     * quietly costs you fat for the day is not a suggestion worth trusting.
     */
    private function improvementSummary(PlateScore $before, PlateScore $after): string
    {
        $improved = [];
        $worsened = [];

        foreach (array_keys(MealFitScore::WEIGHTS) as $macro) {
            $delta = $after->componentScore($macro) - $before->componentScore($macro);

            if ($delta >= 0.3) {
                $improved[$macro] = $delta;
            } elseif ($delta <= -0.5) {
                $worsened[$macro] = $delta;
            }
        }

        arsort($improved);
        asort($worsened);

        $sentence = $improved === []
            ? 'A modest improvement overall.'
            : sprintf(
                'That brings %s closer to what today still needs.',
                $this->list(array_map(
                    fn (string $macro) => $this->macroNoun($macro),
                    array_keys($improved),
                )),
            );

        if ($worsened !== []) {
            // Protein reads the other way round from the rest: less of it is
            // the loss, not more, so "pushes your protein up" would be exactly
            // backwards.
            $clauses = [];
            $raised = [];

            foreach (array_keys($worsened) as $macro) {
                if ($macro === 'protein') {
                    $clauses[] = 'costs you a little protein';

                    continue;
                }

                $raised[] = $this->macroNoun($macro);
            }

            if ($raised !== []) {
                $clauses[] = sprintf('pushes your %s up a little', $this->list($raised));
            }

            $sentence .= sprintf(' It %s for the day.', $this->list($clauses));
        }

        return $sentence;
    }

    private function macroNoun(string $macro): string
    {
        return match ($macro) {
            'carbs' => 'carbohydrate',
            'calories' => 'calories',
            default => $macro,
        };
    }

    /**
     * Runner-up foods worth naming, so the user is not left thinking chicken is
     * the only answer.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $best
     */
    private function alternativeNames(
        array $candidates,
        array $best,
        array $targets,
        array $remaining,
    ): string {
        $chosen = $best['changes'][0]['item_name'] ?? '';
        $scored = [];

        foreach ($candidates as $candidate) {
            $change = $candidate['changes'][0] ?? null;

            if ($change === null || $change['action'] !== 'add_item') {
                continue;
            }

            $name = $change['item_name'];

            if ($name === $chosen || isset($scored[$name])) {
                continue;
            }

            $scored[$name] = $this->scorer
                ->evaluate($candidate['plate']->totals(), $targets, $remaining)
                ->score;
        }

        arsort($scored);
        $names = array_slice(array_keys($scored), 0, 2);

        if ($names === []) {
            return '';
        }

        return Str::ucfirst(sprintf(
            '%s would work about as well.',
            Str::lower(implode(' or ', $names)),
        ));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function describe(
        string $id,
        array $candidate,
        PlateMeal $meal,
        PlateScore $current,
        string $lead,
    ): array {
        $projected = $candidate['plate']->totals();
        // The difference is always measured against the plate the candidate was
        // built from, so it can never be reported against the wrong baseline.
        $before = $meal->totals();

        $detail = trim($lead.' '.$this->improvementSummary($current, $candidate['score']));

        return [
            'id' => $id,
            'title' => $this->titleFor($id),
            'emoji' => $this->emojiFor($id),
            'applicable' => true,
            'unavailable_reason' => null,
            'description' => $this->summarise($candidate['changes']),
            'detail' => $detail,
            'changes' => $candidate['changes'],
            'notes' => $candidate['notes'],
            'macro_difference' => [
                'calories' => $projected['calories'] - $before['calories'],
                'protein' => round($projected['protein'] - $before['protein'], 1),
                'carbs' => round($projected['carbs'] - $before['carbs'], 1),
                'fat' => round($projected['fat'] - $before['fat'], 1),
            ],
            'projected_meal' => $projected,
            'current_score' => $current->score,
            'new_score' => $candidate['score']->score,
        ];
    }

    /** @param list<array<string, mixed>> $changes */
    private function summarise(array $changes): string
    {
        $parts = [];

        foreach ($changes as $change) {
            if ($change['action'] === 'set_portion') {
                $verb = $change['to_portion'] > $change['from_portion'] ? 'Increase' : 'Reduce';
                $parts[] = sprintf(
                    '%s %s from %s%s to %s%s',
                    $verb,
                    $change['item_name'],
                    $this->number($change['from_portion']),
                    $this->unitSuffix($change['portion_unit']),
                    $this->number($change['to_portion']),
                    $this->unitSuffix($change['portion_unit']),
                );

                continue;
            }

            $parts[] = sprintf(
                'Add %s%s of %s',
                $this->number($change['portion_amount']),
                $this->unitSuffix($change['portion_unit']),
                Str::lower($change['item_name']),
            ) . ($change['portion_hint'] !== null ? ' ('.$change['portion_hint'].')' : '');
        }

        return implode(', and ', $parts).'.';
    }

    /** @return array<string, mixed> */
    private function unavailable(string $id, string $reason): array
    {
        return [
            'id' => $id,
            'title' => $this->titleFor($id),
            'emoji' => $this->emojiFor($id),
            'applicable' => false,
            'unavailable_reason' => $reason,
            'description' => null,
            'detail' => null,
            'changes' => [],
            'notes' => [],
            'macro_difference' => null,
            'projected_meal' => null,
            'current_score' => null,
            'new_score' => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Locked macros on an item a portion change is about to touch. Said out
     * loud, because the numbers below would otherwise look wrong to someone who
     * remembers typing that value in themselves.
     *
     * @return list<string>
     */
    private function lockNotes(PlateItem $item): array
    {
        $locked = array_values(array_filter(
            ['calories', 'protein', 'carbs', 'fat'],
            fn (string $macro) => $item->macroIsLocked($macro),
        ));

        if ($locked === []) {
            return [];
        }

        return [sprintf(
            'You edited %s on %s by hand, so changing its portion leaves %s untouched. '
                .'The figures here already account for that.',
            $this->list($locked),
            $item->name,
            count($locked) === 1 ? 'that value' : 'those values',
        )];
    }

    /** @param list<string> $values */
    private function list(array $values): string
    {
        if (count($values) === 1) {
            return $values[0];
        }

        $last = array_pop($values);

        return implode(', ', $values).' and '.$last;
    }

    private function noScalingReason(PlateMeal $meal): string
    {
        foreach ($meal->items as $item) {
            if ($item->isScalable()) {
                return 'Trimming any of these portions would not bring the meal inside your remaining calories.';
            }
        }

        return 'These items were entered by hand, so Smart Plate has no baseline to rescale them from. '
            .'You can still adjust the portions yourself above.';
    }

    /**
     * How far past the remaining calories counts as a problem worth acting on:
     * 30 kcal, or 2% of the day's target, whichever is larger.
     *
     * @param  array{calories:int, protein:int, carbs:int, fat:int}  $targets
     */
    private function materialOvershoot(array $targets): float
    {
        return max(
            self::MIN_MATERIAL_OVERSHOOT,
            $targets['calories'] * self::MIN_MATERIAL_OVERSHOOT_SHARE,
        );
    }

    /**
     * Round a portion to something a person would actually measure. Nobody
     * weighs out 147.3 g of rice.
     */
    private function roundPortion(float $amount, string $unit): float
    {
        $unit = Str::lower(trim($unit));

        if (in_array($unit, ['g', 'ml'], true)) {
            if ($amount >= 100) {
                return round($amount / 10) * 10;
            }

            return $amount >= 20 ? round($amount / 5) * 5 : max(1.0, round($amount));
        }

        if (in_array($unit, ['oz', 'fl oz'], true)) {
            return max(0.5, round($amount * 2) / 2);
        }

        // Cups, slices, pieces, servings: quarters are as fine as it gets.
        return max(0.25, round($amount * 4) / 4);
    }

    /** @param array<string, mixed> $food */
    private function portionHint(array $food, float $portion): ?string
    {
        if (! isset($food['piece_grams']) || $food['piece_grams'] <= 0) {
            return null;
        }

        $pieces = (int) round($portion / $food['piece_grams']);

        if ($pieces < 1) {
            return null;
        }

        return sprintf(
            'about %d %s%s',
            $pieces,
            $food['piece_label'] ?? 'piece',
            $pieces === 1 ? '' : 's',
        );
    }

    private function titleFor(string $id): string
    {
        return match ($id) {
            'boost_protein' => 'Boost Protein',
            'reduce_calories' => 'Reduce Calories',
            default => 'Balance Meal',
        };
    }

    private function emojiFor(string $id): string
    {
        return match ($id) {
            'boost_protein' => '💪',
            'reduce_calories' => '🔥',
            default => '🎯',
        };
    }

    private function trim(float $value): float
    {
        return round($value, 2);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    private function unitSuffix(string $unit): string
    {
        return in_array(Str::lower(trim($unit)), ['g', 'ml'], true) ? $unit : ' '.$unit;
    }

    private function grams(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
