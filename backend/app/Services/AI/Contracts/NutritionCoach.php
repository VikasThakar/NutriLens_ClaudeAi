<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Data\CoachContext;

/**
 * A text provider that can answer a nutrition question against one user's own
 * NutriLens figures.
 *
 * Implementations talk to their provider and return decoded JSON, nothing
 * more. Validating that payload — shape, length, and whether it strayed
 * outside what a nutrition coach may say — is the job of CoachService, so
 * every provider is held to the same contract.
 *
 * The provider is given the context object and the conversation so far. It is
 * never given the user's identity, credentials or database ids; see
 * CoachContext.
 */
interface NutritionCoach
{
    /**
     * @param  list<array{role:string, content:string}>  $history  Prior turns, oldest first, already trimmed
     * @param  string  $message  The new user message
     * @return array<string, mixed> Decoded JSON matching CoachPrompt::responseSchema()
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function reply(CoachContext $context, array $history, string $message): array;

    /** Driver name, stored on the assistant message for auditing. */
    public function providerName(): string;

    /** Concrete model identifier, stored on the assistant message for auditing. */
    public function modelName(): string;
}
