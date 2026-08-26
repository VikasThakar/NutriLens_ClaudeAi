<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\MealVisionAnalyzer;
use App\Services\AI\Data\PreparedImage;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\MealAnalysisPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meal analysis via an OpenAI-compatible chat completions endpoint.
 *
 * Uses raw HTTP through Laravel's client rather than a vendor SDK: this driver
 * also has to serve OpenAI-compatible gateways (Azure, OpenRouter, a local
 * model), which only agree on the wire format.
 */
class OpenAiMealAnalyzer implements MealVisionAnalyzer
{
    public function __construct(
        private readonly MealAnalysisPrompt $prompt,
    ) {
    }

    public function providerName(): string
    {
        return 'openai';
    }

    public function modelName(): string
    {
        return (string) config('ai.providers.openai.model', 'gpt-4o');
    }

    public function analyze(PreparedImage $image): array
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
                ->timeout((float) config('ai.timeout', 90))
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $this->modelName(),
                    'max_completion_tokens' => (int) config('ai.providers.openai.max_tokens', 4000),
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'meal_analysis',
                            'strict' => true,
                            'schema' => $this->prompt->responseSchema(),
                        ],
                    ],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->prompt->systemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'image_url',
                                    'image_url' => ['url' => $image->dataUri()],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $this->prompt->userPrompt(),
                                ],
                            ],
                        ],
                    ],
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
            Log::error('OpenAI-compatible endpoint rejected the request', [
                'status' => $response->status(),
                'body' => $response->json('error.message'),
            ]);

            throw new AiConfigurationException('The AI provider rejected the request.');
        }

        if ($response->failed()) {
            Log::warning('OpenAI-compatible meal analysis failed', [
                'status' => $response->status(),
            ]);

            throw new AiUnavailableException('The AI provider returned an error.');
        }

        $content = $response->json('choices.0.message.content');

        // A content filter or refusal comes back with content null.
        if (! is_string($content) || trim($content) === '') {
            throw new AiResponseException('The AI provider returned no content.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new AiResponseException('The AI provider returned malformed JSON.');
        }

        return $decoded;
    }
}
