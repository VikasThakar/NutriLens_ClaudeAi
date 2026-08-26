<?php

namespace App\Services\AI\Data;

/** A validated meal analysis, ready to hand to the client for review. */
final readonly class AnalyzedMeal
{
    /**
     * @param  list<AnalyzedFoodItem>  $items
     */
    public function __construct(
        public string $mealName,
        public float $confidence,
        public array $items,
        public ?string $notes,
        public string $provider,
        public string $model,
    ) {
    }

    public function totalCalories(): int
    {
        return (int) round(array_sum(array_map(fn (AnalyzedFoodItem $i) => $i->calories, $this->items)));
    }

    public function totalProtein(): float
    {
        return round(array_sum(array_map(fn (AnalyzedFoodItem $i) => $i->protein, $this->items)), 1);
    }

    public function totalCarbs(): float
    {
        return round(array_sum(array_map(fn (AnalyzedFoodItem $i) => $i->carbs, $this->items)), 1);
    }

    public function totalFat(): float
    {
        return round(array_sum(array_map(fn (AnalyzedFoodItem $i) => $i->fat, $this->items)), 1);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meal_name' => $this->mealName,
            'confidence' => $this->confidence,
            'notes' => $this->notes,
            'items' => array_map(fn (AnalyzedFoodItem $item) => $item->toArray(), $this->items),
            'totals' => [
                'calories' => $this->totalCalories(),
                'protein' => $this->totalProtein(),
                'carbs' => $this->totalCarbs(),
                'fat' => $this->totalFat(),
            ],
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }
}
