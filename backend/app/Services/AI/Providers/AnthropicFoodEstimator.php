<?php

namespace App\Services\AI\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Services\AI\Contracts\FoodNutritionEstimator;
use App\Services\AI\Data\FoodQuery;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\FoodEstimationPrompt;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Structured food estimation via the Anthropic Messages API. */
class AnthropicFoodEstimator implements FoodNutritionEstimator
{
    public function __construct(private readonly FoodEstimationPrompt $prompt)
    {
    }

    public function providerName(): string
    {
        return 'anthropic';
    }

    public function modelName(): string
    {
        return (string) config('ai.estimation.model')
            ?: (string) config('ai.providers.anthropic.model', 'claude-opus-5');
    }

    public function estimate(FoodQuery $query): array
    {
        $apiKey = (string) config('ai.providers.anthropic.api_key');

        if (trim($apiKey) === '') {
            throw new AiConfigurationException('AI_API_KEY is not set.');
        }

        $client = new Client(
            apiKey: $apiKey,
            baseUrl: config('ai.providers.anthropic.base_url') ?: null,
            requestOptions: ['timeout' => (float) config('ai.timeout', 90)],
        );

        try {
            $message = $client->messages->create(
                model: $this->modelName(),
                maxTokens: (int) config('ai.estimation.max_tokens', 4000),
                system: [
                    [
                        'type' => 'text',
                        'text' => $this->prompt->systemPrompt(),
                        // Identical on every request, so the cached prefix makes
                        // repeat calls substantially cheaper.
                        'cacheControl' => ['type' => 'ephemeral'],
                    ],
                ],
                messages: [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $this->prompt->userPrompt($query)],
                        ],
                    ],
                ],
                outputConfig: [
                    'effort' => (string) config('ai.estimation.effort', 'low'),
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $this->prompt->responseSchema(),
                    ],
                ],
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            Log::error('Anthropic rejected the NutriLens API key', ['status' => $e->status]);

            throw new AiConfigurationException('Anthropic rejected the API key.', 0, $e);
        } catch (BadRequestException|UnprocessableEntityException $e) {
            Log::error('Anthropic rejected the food estimation request', [
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            throw new AiConfigurationException('Anthropic rejected the request.', 0, $e);
        } catch (APIStatusException $e) {
            Log::warning('Anthropic food estimation failed', ['status' => $e->status]);

            throw new AiUnavailableException('Anthropic returned an error.', 0, $e);
        } catch (APIConnectionException $e) {
            Log::warning('Could not reach Anthropic', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('Could not reach Anthropic.', 0, $e);
        } catch (Throwable $e) {
            Log::warning('Anthropic food estimation threw', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('The AI request failed.', 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiResponseException('The model declined to estimate these foods.');
        }

        $json = null;

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
                break;
            }
        }

        if ($json === null || trim($json) === '') {
            throw new AiResponseException('Anthropic returned no text content.');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new AiResponseException('Anthropic returned malformed JSON.');
        }

        return $decoded;
    }
}
