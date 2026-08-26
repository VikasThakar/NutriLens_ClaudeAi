<?php

/*
|--------------------------------------------------------------------------
| AI vision configuration
|--------------------------------------------------------------------------
|
| The meal-analysis provider is swappable. Nothing here is hard-coded — the
| API key lives only in the environment and is never sent to the frontend.
|
| Set AI_PROVIDER=fake to develop the whole capture → review → save flow with
| no key and no network calls.
|
*/

return [

    /**
     * Active driver: "anthropic", "openai" or "fake".
     */
    'provider' => env('AI_PROVIDER', 'anthropic'),

    /**
     * Seconds to wait for the vision model. Analysis of a single photo
     * typically completes well inside this; the ceiling exists so a hung
     * upstream request cannot hold a PHP worker forever.
     */
    'timeout' => (float) env('AI_TIMEOUT', 90),

    /**
     * Photos are downscaled before they are sent upstream: it cuts cost and
     * latency substantially with no measurable loss in recognition quality,
     * and keeps requests inside provider payload limits.
     */
    'image' => [
        'max_edge' => (int) env('AI_IMAGE_MAX_EDGE', 1568),
        'jpeg_quality' => (int) env('AI_IMAGE_JPEG_QUALITY', 82),
    ],

    /**
     * Guardrails applied to whatever the model returns, before it reaches the
     * client. A model that ignores them fails validation rather than polluting
     * the database.
     */
    'limits' => [
        'max_items' => (int) env('AI_MAX_ITEMS', 12),
        'max_calories_per_item' => 5000,
        'max_grams_per_macro' => 1000,
    ],

    /**
     * Weekly nutrition insights.
     *
     * A separate block because the job is different from vision: it is short
     * text over a small numeric payload, so it wants far fewer tokens and less
     * reasoning effort than analysing a photograph. Leave AI_INSIGHTS_MODEL
     * blank to use the same model as meal analysis.
     */
    'insights' => [
        'model' => env('AI_INSIGHTS_MODEL') ?: null,
        'max_tokens' => (int) (env('AI_INSIGHTS_MAX_TOKENS') ?: 2000),
        /** low | medium | high | xhigh | max — Anthropic only. */
        'effort' => env('AI_INSIGHTS_EFFORT') ?: 'low',
    ],

    /**
     * Structured food estimation — the partner API's non-image endpoint.
     *
     * Cheaper again than insights: a short list of named foods in, macros out,
     * no image tokens and no long reasoning. Leave AI_ESTIMATION_MODEL blank to
     * use the same model as meal analysis.
     */
    'estimation' => [
        'model' => env('AI_ESTIMATION_MODEL') ?: null,
        'max_tokens' => (int) (env('AI_ESTIMATION_MAX_TOKENS') ?: 4000),
        /** low | medium | high | xhigh | max — Anthropic only. */
        'effort' => env('AI_ESTIMATION_EFFORT') ?: 'low',
        /** Hard cap on how many foods one partner request may contain. */
        'max_items' => (int) (env('AI_ESTIMATION_MAX_ITEMS') ?: 20),
    ],

    'providers' => [

        /*
         * `?:` rather than an env() default throughout: a variable that is
         * present but blank (AI_MODEL= in .env) yields "", which would
         * otherwise slip past a default and be sent upstream as the model name.
         */

        'anthropic' => [
            'api_key' => env('AI_API_KEY'),
            'model' => env('AI_MODEL') ?: 'claude-opus-5',
            'max_tokens' => (int) (env('AI_MAX_TOKENS') ?: 8000),
            /** low | medium | high | xhigh | max */
            'effort' => env('AI_EFFORT') ?: 'medium',
            /** Optional override, e.g. a gateway in front of the API. */
            'base_url' => env('AI_BASE_URL') ?: null,
        ],

        'openai' => [
            'api_key' => env('AI_API_KEY'),
            'model' => env('AI_MODEL') ?: 'gpt-4o',
            'max_tokens' => (int) (env('AI_MAX_TOKENS') ?: 4000),
            'base_url' => env('AI_BASE_URL') ?: 'https://api.openai.com/v1',
        ],

        'fake' => [
            /** Milliseconds of simulated latency, so the loading UI is exercised. */
            'delay_ms' => (int) env('AI_FAKE_DELAY_MS', 1200),
        ],

    ],

];
