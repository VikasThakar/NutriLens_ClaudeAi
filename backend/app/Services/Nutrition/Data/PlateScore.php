<?php

namespace App\Services\Nutrition\Data;

/**
 * A Meal Fit Score together with the working that produced it.
 *
 * The components are kept rather than discarded because the score on its own is
 * not the feature: "8.3" is only useful next to "protein is carrying 45 g of
 * the 68 g a meal this size should be". Everything the UI says about the
 * breakdown is read off this object, so the prose and the number can never
 * disagree.
 */
readonly class PlateScore
{
    /**
     * @param  float  $score  0–10, one decimal
     * @param  array<string, array<string, mixed>>  $components  Keyed by macro
     */
    public function __construct(
        public float $score,
        public array $components,
    ) {
    }

    public function component(string $macro): array
    {
        return $this->components[$macro] ?? [];
    }

    public function componentScore(string $macro): float
    {
        return (float) ($this->components[$macro]['score'] ?? 0.0);
    }

    /**
     * A short label for the score as a whole. Bands rather than a raw number,
     * because "8.3" means little without being told that is good.
     */
    public function rating(): string
    {
        return match (true) {
            $this->score >= 9.0 => 'excellent_fit',
            $this->score >= 7.5 => 'great_fit',
            $this->score >= 6.0 => 'good_fit',
            $this->score >= 4.0 => 'fair_fit',
            default => 'poor_fit',
        };
    }

    public function ratingLabel(): string
    {
        return match ($this->rating()) {
            'excellent_fit' => 'Excellent fit',
            'great_fit' => 'Great fit',
            'good_fit' => 'Good fit',
            'fair_fit' => 'Fair fit',
            default => 'Needs attention',
        };
    }

    /** The macro dragging the score down hardest, weighted as it is scored. */
    public function weakest(): ?string
    {
        $worst = null;
        $worstLoss = 0.0;

        foreach (\App\Services\Nutrition\MealFitScore::WEIGHTS as $macro => $weight) {
            $loss = (10.0 - $this->componentScore($macro)) * $weight;

            if ($loss > $worstLoss) {
                $worstLoss = $loss;
                $worst = $macro;
            }
        }

        return $worst;
    }
}
