<?php

namespace App\Services\Nutrition\Data;

/**
 * One food item on the plate being analysed, as the review screen currently
 * holds it — the meal is not saved yet, so this is deliberately not a Meal
 * model.
 *
 * The important behaviour is `withPortion()`, which reproduces the frontend's
 * portion scaling **exactly**: same baseline, same locked-macro rule, same
 * rounding. That equivalence is what lets Smart Plate promise "this change will
 * add 31 g of protein" and have the number still be true after the user taps
 * Apply and the client does the scaling itself.
 */
readonly class PlateItem
{
    /**
     * @param  list<string>  $lockedMacros  Macros the user typed by hand
     */
    public function __construct(
        public string $name,
        public float $portionAmount,
        public string $portionUnit,
        public int $calories,
        public float $protein,
        public float $carbs,
        public float $fat,
        public ?float $basePortionAmount,
        public ?int $baseCalories,
        public ?float $baseProtein,
        public ?float $baseCarbs,
        public ?float $baseFat,
        public ?float $confidence,
        public array $lockedMacros,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload  One validated `items.*` entry
     */
    public static function fromRequest(array $payload): self
    {
        return new self(
            name: trim((string) $payload['name']),
            portionAmount: (float) $payload['portion_amount'],
            portionUnit: trim((string) $payload['portion_unit']),
            calories: (int) round((float) $payload['calories']),
            protein: round((float) $payload['protein'], 1),
            carbs: round((float) $payload['carbs'], 1),
            fat: round((float) $payload['fat'], 1),
            basePortionAmount: isset($payload['base_portion_amount'])
                ? (float) $payload['base_portion_amount']
                : null,
            baseCalories: isset($payload['base_calories'])
                ? (int) round((float) $payload['base_calories'])
                : null,
            baseProtein: isset($payload['base_protein']) ? (float) $payload['base_protein'] : null,
            baseCarbs: isset($payload['base_carbs']) ? (float) $payload['base_carbs'] : null,
            baseFat: isset($payload['base_fat']) ? (float) $payload['base_fat'] : null,
            confidence: isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            lockedMacros: array_values(array_intersect(
                array_map('strval', (array) ($payload['locked_macros'] ?? [])),
                ['calories', 'protein', 'carbs', 'fat'],
            )),
        );
    }

    /**
     * A freshly suggested food. Its current values double as its baseline, so
     * the user can rescale it afterwards exactly like any other item.
     */
    public static function suggested(
        string $name,
        float $portionAmount,
        string $portionUnit,
        int $calories,
        float $protein,
        float $carbs,
        float $fat,
    ): self {
        return new self(
            name: $name,
            portionAmount: $portionAmount,
            portionUnit: $portionUnit,
            calories: $calories,
            protein: $protein,
            carbs: $carbs,
            fat: $fat,
            basePortionAmount: $portionAmount,
            baseCalories: $calories,
            baseProtein: $protein,
            baseCarbs: $carbs,
            baseFat: $fat,
            // Not an AI estimate and not a measurement the user made — it comes
            // from NutriLens's own reference table, so it carries no confidence
            // score of its own.
            confidence: null,
            lockedMacros: [],
        );
    }

    /** Whether there is a baseline to scale a portion change from. */
    public function isScalable(): bool
    {
        return $this->basePortionAmount !== null && $this->basePortionAmount > 0;
    }

    public function macroIsLocked(string $macro): bool
    {
        return in_array($macro, $this->lockedMacros, true);
    }

    /** Macros a portion change would actually move. */
    public function unlockedMacros(): array
    {
        return array_values(array_filter(
            ['calories', 'protein', 'carbs', 'fat'],
            fn (string $macro) => ! $this->macroIsLocked($macro) && $this->baseFor($macro) !== null,
        ));
    }

    /** @return array{calories:int, protein:float, carbs:float, fat:float} */
    public function macros(): array
    {
        return [
            'calories' => $this->calories,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'fat' => $this->fat,
        ];
    }

    public function macro(string $macro): float
    {
        return (float) ($this->macros()[$macro] ?? 0);
    }

    /**
     * How much of a macro one unit of this item's portion carries, taken from
     * the baseline rather than the current values — which is the same reference
     * point `withPortion()` scales from, so the two always agree.
     *
     * Null when there is nothing to scale from.
     */
    public function basePerUnit(string $macro): ?float
    {
        $base = $this->baseFor($macro);

        if (! $this->isScalable() || $base === null) {
            return null;
        }

        return (float) $base / (float) $this->basePortionAmount;
    }

    /**
     * The same item at a different portion.
     *
     * Mirrors `setItemPortion` in `frontend/lib/meal-draft.ts`:
     *
     *  - With no baseline, nothing is scaled. There is no reference point, so
     *    guessing would be worse than doing nothing.
     *  - A macro the user typed by hand is locked and is never overwritten.
     *  - Calories round to whole numbers, grams to one decimal.
     */
    public function withPortion(float $portion): self
    {
        if (! $this->isScalable() || $portion <= 0) {
            return $this->cloneWith(portion: $portion);
        }

        $ratio = $portion / (float) $this->basePortionAmount;

        return $this->cloneWith(
            portion: $portion,
            calories: $this->macroIsLocked('calories') || $this->baseCalories === null
                ? $this->calories
                : (int) round($this->baseCalories * $ratio),
            protein: $this->scaled('protein', $ratio),
            carbs: $this->scaled('carbs', $ratio),
            fat: $this->scaled('fat', $ratio),
        );
    }

    private function scaled(string $macro, float $ratio): float
    {
        $base = $this->baseFor($macro);

        if ($this->macroIsLocked($macro) || $base === null) {
            return $this->macro($macro);
        }

        return round(max(0.0, $base * $ratio), 1);
    }

    private function baseFor(string $macro): int|float|null
    {
        return match ($macro) {
            'calories' => $this->baseCalories,
            'protein' => $this->baseProtein,
            'carbs' => $this->baseCarbs,
            'fat' => $this->baseFat,
            default => null,
        };
    }

    private function cloneWith(
        float $portion,
        ?int $calories = null,
        ?float $protein = null,
        ?float $carbs = null,
        ?float $fat = null,
    ): self {
        return new self(
            name: $this->name,
            portionAmount: $portion,
            portionUnit: $this->portionUnit,
            calories: $calories ?? $this->calories,
            protein: $protein ?? $this->protein,
            carbs: $carbs ?? $this->carbs,
            fat: $fat ?? $this->fat,
            basePortionAmount: $this->basePortionAmount,
            baseCalories: $this->baseCalories,
            baseProtein: $this->baseProtein,
            baseCarbs: $this->baseCarbs,
            baseFat: $this->baseFat,
            confidence: $this->confidence,
            lockedMacros: $this->lockedMacros,
        );
    }
}
