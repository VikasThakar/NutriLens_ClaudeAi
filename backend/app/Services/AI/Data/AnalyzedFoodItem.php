<?php

namespace App\Services\AI\Data;

/** One food the model identified in the photo. */
final readonly class AnalyzedFoodItem
{
    public function __construct(
        public string $name,
        public float $portionAmount,
        public string $portionUnit,
        public int $calories,
        public float $protein,
        public float $carbs,
        public float $fat,
        public float $confidence,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'portion_amount' => $this->portionAmount,
            'portion_unit' => $this->portionUnit,
            'calories' => $this->calories,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'fat' => $this->fat,
            'confidence' => $this->confidence,
        ];
    }
}
