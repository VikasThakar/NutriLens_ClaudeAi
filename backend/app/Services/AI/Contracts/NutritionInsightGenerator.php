<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Data\WeeklyNutritionSummary;

/**
 * A text provider that can turn an aggregated week of nutrition into a short
 * narrative summary.
 *
 * Implementations talk to their provider and return decoded JSON, nothing
 * more. Validating that payload — shape, length, and whether every number in
 * the prose can be traced back to the aggregates we supplied — is the job of
 * WeeklyInsightService, so every provider is held to the same contract.
 *
 * The provider is never given meals, photos, food names or anything else
 * beyond the aggregate figures in the summary.
 */
interface NutritionInsightGenerator
{
    /**
     * @return array<string, mixed> Decoded JSON matching WeeklyInsightPrompt::responseSchema()
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function generate(WeeklyNutritionSummary $summary): array;

    /** Driver name, stored on the insight for auditing. */
    public function providerName(): string;

    /** Concrete model identifier, stored on the insight for auditing. */
    public function modelName(): string;
}
