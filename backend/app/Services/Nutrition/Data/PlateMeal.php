<?php

namespace App\Services\Nutrition\Data;

/**
 * The unsaved meal Smart Plate is analysing.
 *
 * Immutable, because the optimizer works by producing candidate plates and
 * scoring them: it needs to try "what if the rice were 150 g" a dozen times
 * without ever disturbing the real one.
 *
 * Totals are summed and rounded the same way `draftTotals` does on the client,
 * so the figure Smart Plate reasons about is the figure in the sticky totals bar.
 */
readonly class PlateMeal
{
    /** @param list<PlateItem> $items */
    public function __construct(public array $items)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $items  Validated `items` payload
     */
    public static function fromRequest(array $items): self
    {
        return new self(array_values(array_map(
            fn (array $item) => PlateItem::fromRequest($item),
            $items,
        )));
    }

    /** @return array{calories:int, protein:float, carbs:float, fat:float} */
    public function totals(): array
    {
        $totals = ['calories' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];

        foreach ($this->items as $item) {
            foreach ($totals as $macro => $value) {
                $totals[$macro] = $value + $item->macro($macro);
            }
        }

        return [
            'calories' => (int) round($totals['calories']),
            'protein' => round($totals['protein'], 1),
            'carbs' => round($totals['carbs'], 1),
            'fat' => round($totals['fat'], 1),
        ];
    }

    public function withItem(int $index, PlateItem $item): self
    {
        $items = $this->items;
        $items[$index] = $item;

        return new self(array_values($items));
    }

    public function withAddedItem(PlateItem $item): self
    {
        return new self([...$this->items, $item]);
    }

    /**
     * Nothing to analyse: no items, or items with no nutrition in them at all.
     * A half-typed manual meal lands here, and gets guidance rather than a
     * score computed from zeroes.
     */
    public function isEmpty(): bool
    {
        $totals = $this->totals();

        return $this->items === []
            || ($totals['calories'] <= 0
                && $totals['protein'] <= 0
                && $totals['carbs'] <= 0
                && $totals['fat'] <= 0);
    }
}
