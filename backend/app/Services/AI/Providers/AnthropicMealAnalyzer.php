<?php

namespace App\Services\AI\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Services\AI\Contracts\MealVisionAnalyzer;
use App\Services\AI\Data\PreparedImage;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\MealAnalysisPrompt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meal analysis via the Anthropic Messages API.
 *
 * Uses structured outputs (`outputConfig.format`) so the model is constrained
 * to our JSON schema at generation time rather than asked to behave.
 */
class AnthropicMealAnalyzer implements MealVisionAnalyzer
{
    public function __construct(
        private readonly MealAnalysisPrompt $prompt,
    ) {
    }

    public function providerName(): string
    {
        return 'anthropic';
    }

    public function modelName(): string
    {
        return (string) config('ai.providers.anthropic.model', 'claude-opus-5');
    }

    public function analyze(PreparedImage $image): array
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
                maxTokens: (int) config('ai.providers.anthropic.max_tokens', 8000),
                system: [
                    [
                        'type' => 'text',
                        'text' => $this->prompt->systemPrompt(),
                        // The prompt and schema are identical on every request,
                        // so caching the prefix cuts cost on repeat analyses.
                        'cacheControl' => ['type' => 'ephemeral'],
                    ],
                ],
                messages: [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'mediaType' => $image->mimeType,
                                    'data' => $image->base64(),
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => $this->prompt->userPrompt(),
                            ],
                        ],
                    ],
                ],
                outputConfig: [
                    'effort' => (string) config('ai.providers.anthropic.effort', 'medium'),
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $this->prompt->responseSchema(),
                    ],
                ],
            );
        }

        // Most specific first: a bad key and a rate limit need different
        // handling, and only one of them is worth retrying.
        catch (AuthenticationException|PermissionDeniedException $e) {
            Log::error('Anthropic rejected the NutriLens API key', ['status' => $e->status]);

            throw new AiConfigurationException('Anthropic rejected the API key.', 0, $e);
        } catch (BadRequestException|UnprocessableEntityException $e) {
            // Our request shape or model name is wrong — a user retry cannot fix
            // it, so log loudly and let them fall back to manual entry.
            Log::error('Anthropic rejected the NutriLens request', [
                'status' => $e->status,
                'type' => $e->type?->value,
                'message' => $e->getMessage(),
            ]);

            throw new AiConfigurationException('Anthropic rejected the request.', 0, $e);
        } catch (APIStatusException $e) {
            // Rate limits and 5xx land here — transient, worth retrying.
            Log::warning('Anthropic meal analysis failed', [
                'status' => $e->status,
                'type' => $e->type?->value,
            ]);

            throw new AiUnavailableException('Anthropic returned an error.', 0, $e);
        } catch (APIConnectionException $e) {
            // Also covers APITimeoutException, which extends this.
            Log::warning('Could not reach Anthropic', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('Could not reach Anthropic.', 0, $e);
        } catch (Throwable $e) {
            Log::warning('Anthropic meal analysis threw', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('The AI request failed.', 0, $e);
        }

        // A safety refusal is a successful HTTP call with no usable content.
        if ($message->stopReason === 'refusal') {
            throw new AiResponseException('The model declined to analyse this image.');
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
