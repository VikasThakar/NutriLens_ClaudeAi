<?php

namespace App\Services\AI\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Services\AI\Contracts\NutritionInsightGenerator;
use App\Services\AI\Data\WeeklyNutritionSummary;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\WeeklyInsightPrompt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Weekly insights via the Anthropic Messages API.
 *
 * Same shape as AnthropicMealAnalyzer — structured outputs, a cached system
 * prefix, and errors mapped onto the shared AiException hierarchy so the
 * controller layer does not care which provider failed.
 */
class AnthropicInsightGenerator implements NutritionInsightGenerator
{
    public function __construct(private readonly WeeklyInsightPrompt $prompt)
    {
    }

    public function providerName(): string
    {
        return 'anthropic';
    }

    public function modelName(): string
    {
        return (string) config('ai.insights.model')
            ?: (string) config('ai.providers.anthropic.model', 'claude-opus-5');
    }

    public function generate(WeeklyNutritionSummary $summary): array
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
                maxTokens: (int) config('ai.insights.max_tokens', 2000),
                system: [
                    [
                        'type' => 'text',
                        'text' => $this->prompt->systemPrompt(),
                        // Identical on every request, so caching the prefix
                        // makes the weekly call cheap after the first.
                        'cacheControl' => ['type' => 'ephemeral'],
                    ],
                ],
                messages: [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $this->prompt->userPrompt($summary)],
                        ],
                    ],
                ],
                outputConfig: [
                    'effort' => (string) config('ai.insights.effort', 'low'),
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
            Log::error('Anthropic rejected the weekly insight request', [
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            throw new AiConfigurationException('Anthropic rejected the request.', 0, $e);
        } catch (APIStatusException $e) {
            Log::warning('Anthropic weekly insight failed', ['status' => $e->status]);

            throw new AiUnavailableException('Anthropic returned an error.', 0, $e);
        } catch (APIConnectionException $e) {
            Log::warning('Could not reach Anthropic', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('Could not reach Anthropic.', 0, $e);
        } catch (Throwable $e) {
            Log::warning('Anthropic weekly insight threw', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('The AI request failed.', 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiResponseException('The model declined to write this summary.');
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
