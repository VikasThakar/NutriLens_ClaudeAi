<?php

namespace App\Services\AI\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Services\AI\Contracts\NutritionCoach;
use App\Services\AI\CoachPrompt;
use App\Services\AI\Data\CoachContext;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The AI Coach via the Anthropic Messages API.
 *
 * Same shape as the other Anthropic drivers — structured outputs, a cached
 * system prefix, and errors mapped onto the shared AiException hierarchy so
 * the controller layer does not care which provider failed.
 */
class AnthropicNutritionCoach implements NutritionCoach
{
    public function __construct(private readonly CoachPrompt $prompt)
    {
    }

    public function providerName(): string
    {
        return 'anthropic';
    }

    public function modelName(): string
    {
        return (string) config('ai.coach.model')
            ?: (string) config('ai.providers.anthropic.model', 'claude-opus-5');
    }

    public function reply(CoachContext $context, array $history, string $message): array
    {
        $apiKey = (string) config('ai.providers.anthropic.api_key');

        if (trim($apiKey) === '') {
            throw new AiConfigurationException('AI_API_KEY is not set.');
        }

        $client = new Client(
            apiKey: $apiKey,
            baseUrl: config('ai.providers.anthropic.base_url') ?: null,
            requestOptions: ['timeout' => (float) config('ai.coach.timeout')],
        );

        try {
            $response = $client->messages->create(
                model: $this->modelName(),
                maxTokens: (int) config('ai.coach.max_tokens', 1200),
                system: [
                    [
                        'type' => 'text',
                        'text' => $this->prompt->systemPrompt(),
                        // Identical on every request, so caching the prefix
                        // makes every turn after the first materially cheaper.
                        'cacheControl' => ['type' => 'ephemeral'],
                    ],
                ],
                messages: $this->messages($context, $history, $message),
                outputConfig: [
                    'effort' => (string) config('ai.coach.effort', 'low'),
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $this->prompt->responseSchema(),
                    ],
                ],
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            Log::error('Anthropic rejected the NutriLens API key', ['status' => $e->status]);

            throw new AiConfigurationException('Anthropic rejected the API key.', 0, $e);
        } catch (RateLimitException $e) {
            Log::warning('Anthropic rate-limited the AI Coach', ['status' => $e->status]);

            throw new AiUnavailableException('Anthropic is rate limiting requests.', 0, $e);
        } catch (BadRequestException|UnprocessableEntityException $e) {
            Log::error('Anthropic rejected the AI Coach request', [
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            throw new AiConfigurationException('Anthropic rejected the request.', 0, $e);
        } catch (APIStatusException $e) {
            Log::warning('Anthropic AI Coach request failed', ['status' => $e->status]);

            throw new AiUnavailableException('Anthropic returned an error.', 0, $e);
        } catch (APIConnectionException $e) {
            Log::warning('Could not reach Anthropic', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('Could not reach Anthropic.', 0, $e);
        } catch (Throwable $e) {
            Log::warning('Anthropic AI Coach threw', ['message' => $e->getMessage()]);

            throw new AiUnavailableException('The AI request failed.', 0, $e);
        }

        if ($response->stopReason === 'refusal') {
            throw new AiResponseException('The model declined to answer this question.');
        }

        $json = null;

        foreach ($response->content as $block) {
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

    /**
     * Prior turns verbatim, then one final user turn carrying the freshly
     * built context alongside the new question.
     *
     * @param  list<array{role:string, content:string}>  $history
     * @return list<array<string, mixed>>
     */
    private function messages(CoachContext $context, array $history, string $message): array
    {
        $messages = [];

        foreach ($history as $turn) {
            $messages[] = [
                'role' => $turn['role'],
                'content' => [['type' => 'text', 'text' => $turn['content']]],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $this->prompt->userTurn($context, $message)],
            ],
        ];

        return $messages;
    }
}
