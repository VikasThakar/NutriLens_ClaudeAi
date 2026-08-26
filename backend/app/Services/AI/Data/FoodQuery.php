<?php

namespace App\Services\AI\Data;

/**
 * A partner's structured description of a meal: what the foods are and how much
 * of each. No photograph, no user account, no identity — just the foods.
 */
final readonly class FoodQuery
{
    /**
     * @param  list<array{name:string, portion_amount:float, portion_unit:string, brand?:?string}>  $items
     */
    public function __construct(
        public array $items,
        public ?string $mealName = null,
        public ?string $notes = null,
    ) {
    }

    /** The payload sent upstream. Compact by design. */
    public function toPayload(): array
    {
        return array_values(array_map(fn (array $item) => array_filter([
            'name' => $item['name'],
            'brand' => $item['brand'] ?? null,
            'portion_amount' => $item['portion_amount'],
            'portion_unit' => $item['portion_unit'],
        ], fn ($value) => $value !== null), $this->items));
    }

    public function count(): int
    {
        return count($this->items);
    }
}
