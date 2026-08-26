<?php

namespace App\Services\AI\Providers;

use App\Services\AI\CoachPrompt;
use App\Services\AI\Contracts\NutritionCoach;
use App\Services\AI\Data\CoachContext;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The AI Coach via an OpenAI-compatible chat completions endpoint.
 *
 * Raw HTTP rather than a vendor SDK, for the same reason as the other OpenAI
 * drivers in NutriLens: this driver also has to serve OpenAI-compatible
 * gateways, which only agree on the wire format.
 */
class OpenAiNutritionCoach implements NutritionCoach
{
    public function __construct(private readonly CoachPrompt $prompt)
    {
    }

    public function providerName(): string
    {
        return 'openai';
    }

    public function modelName(): string
    {
        return (string) config('ai.coach.model')
            ?: (string) config('ai.providers.openai.model', 'gpt-4o');
    }

    public function reply(CoachContext $context, array $history, string $message): array
    {
        $apiKey = (string) config('ai.providers.openai.api_key');

        if (trim($apiKey) === '') {
            throw new AiConfigurationException('AI_API_KEY is not set.');
        }

        $baseUrl = rtrim(
            (string) config('ai.providers.openai.base_url', 'https://api.openai.com/v1'),
            '/'
        );

        try {
            $response = Http::withToken($apiKey)
                ->timeout((float) config('ai.coach.timeout'))
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $this->modelName(),
                    'max_completion_tokens' => (int) config('ai.coach.max_tokens', 1200),
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'nutrilens_coach_reply',
                            'strict' => true,
                            'schema' => $this->prompt->responseSchema(),
                        ],
                    ],
                    'messages' => $this->messages($context, $history, $message),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Could not reach the OpenAI-compatible endpoint', [
                'message' => $e->getMessage(),
            ]);

            throw new AiUnavailableException('Could not reach the AI provider.', 0, $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            Log::error('OpenAI-compatible endpoint rejected the API key', [
                'status' => $response->status(),
            ]);

            throw new AiConfigurationException('The AI provider rejected the API key.');
        }

        if ($response->status() === 400 || $response->status() === 422) {
            Log::error('OpenAI-compatible endpoint rejected the AI Coach request', [
                'status' => $response->status(),
                'body' => $response->json('error.message'),
            ]);

            throw new AiConfigurationException('The AI provider rejected the request.');
        }

        if ($response->failed()) {
            Log::warning('OpenAI-compatible AI Coach request failed', [
                'status' => $response->status(),
            ]);

            throw new AiUnavailableException('The AI provider returned an error.');
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new AiResponseException('The AI provider returned no content.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new AiResponseException('The AI provider returned malformed JSON.');
        }

        return $decoded;
    }

    /**
     * @param  list<array{role:string, content:string}>  $history
     * @return list<array{role:string, content:string}>
     */
    private function messages(CoachContext $context, array $history, string $message): array
    {
        return [
            ['role' => 'system', 'content' => $this->prompt->systemPrompt()],
            ...$history,
            ['role' => 'user', 'content' => $this->prompt->userTurn($context, $message)],
        ];
    }
}
