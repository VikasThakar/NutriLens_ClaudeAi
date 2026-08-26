<?php

namespace App\Providers;

use App\Services\AI\Contracts\FoodNutritionEstimator;
use App\Services\AI\Contracts\MealVisionAnalyzer;
use App\Services\AI\Contracts\NutritionInsightGenerator;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Providers\AnthropicFoodEstimator;
use App\Services\AI\Providers\AnthropicInsightGenerator;
use App\Services\AI\Providers\AnthropicMealAnalyzer;
use App\Services\AI\Providers\FakeFoodEstimator;
use App\Services\AI\Providers\FakeInsightGenerator;
use App\Services\AI\Providers\FakeMealAnalyzer;
use App\Services\AI\Providers\OpenAiFoodEstimator;
use App\Services\AI\Providers\OpenAiInsightGenerator;
use App\Services\AI\Providers\OpenAiMealAnalyzer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * NutriLens has three AI capabilities, each with one driver per provider.
     * A single AI_PROVIDER value selects all three, so there is no way to end up
     * with a real vision model and a fake nutrition table.
     *
     * @var array<string, array<string, class-string>>
     */
    private const DRIVERS = [
        // Meal photo → items, portions and macros.
        MealVisionAnalyzer::class => [
            'anthropic' => AnthropicMealAnalyzer::class,
            'openai' => OpenAiMealAnalyzer::class,
            'fake' => FakeMealAnalyzer::class,
        ],

        // A week of aggregates → a short written summary.
        NutritionInsightGenerator::class => [
            'anthropic' => AnthropicInsightGenerator::class,
            'openai' => OpenAiInsightGenerator::class,
            'fake' => FakeInsightGenerator::class,
        ],

        // Named foods and portions → macros. Powers the partner API's
        // structured endpoint.
        FoodNutritionEstimator::class => [
            'anthropic' => AnthropicFoodEstimator::class,
            'openai' => OpenAiFoodEstimator::class,
            'fake' => FakeFoodEstimator::class,
        ],
    ];

    public function register(): void
    {
        // Resolved lazily so an unconfigured provider only fails when that
        // capability is actually used — the rest of the app boots regardless.
        foreach (self::DRIVERS as $contract => $drivers) {
            $this->app->bind($contract, fn () => $this->driver($contract, $drivers));
        }
    }

    public function boot(): void
    {
        //
    }

    /**
     * @param  array<string, class-string>  $drivers
     */
    private function driver(string $contract, array $drivers): object
    {
        $provider = (string) config('ai.provider', 'anthropic');

        $class = $drivers[$provider] ?? null;

        if ($class === null) {
            throw new AiConfigurationException(sprintf(
                'Unknown AI_PROVIDER "%s" for %s. Supported: %s.',
                $provider,
                class_basename($contract),
                implode(', ', array_keys($drivers)),
            ));
        }

        return $this->app->make($class);
    }
}
