<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Data\PreparedImage;

/**
 * A vision provider that can read a meal photo and return a raw analysis
 * payload.
 *
 * Implementations are responsible only for talking to their provider and
 * returning decoded JSON. Validating that payload is the job of
 * MealAnalysisService, so every provider is held to the same contract.
 */
interface MealVisionAnalyzer
{
    /**
     * @return array<string, mixed> Decoded JSON matching MealAnalysisPrompt::responseSchema()
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function analyze(PreparedImage $image): array;

    /** Driver name, stored on the meal for auditing. */
    public function providerName(): string;

    /** Concrete model identifier, stored on the meal for auditing. */
    public function modelName(): string;
}
