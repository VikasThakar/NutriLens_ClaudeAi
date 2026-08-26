<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Data\FoodQuery;

/**
 * A provider that can estimate nutrition for foods described in words rather
 * than shown in a photograph.
 *
 * This is the text counterpart to MealVisionAnalyzer, and follows the same
 * contract: the implementation only talks to its provider and returns decoded
 * JSON. Validating and normalising that payload is FoodEstimationService's job,
 * so every provider is held to the same standard and swapping AI_PROVIDER never
 * changes the shape of what a partner receives.
 */
interface FoodNutritionEstimator
{
    /**
     * @return array<string, mixed> Decoded JSON matching FoodEstimationPrompt::responseSchema()
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function estimate(FoodQuery $query): array;

    /** Driver name, echoed back to the partner for auditing. */
    public function providerName(): string;

    /** Concrete model identifier, echoed back to the partner for auditing. */
    public function modelName(): string;
}
