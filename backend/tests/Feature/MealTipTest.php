<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "NutriLens Tip" that follows a saved meal.
 *
 * The most important property under test is what the tip *does not* do: it
 * never calls an AI provider. Several tests below deliberately point the app at
 * a real provider with no key, which would fail loudly if a request were made.
 */
class MealTipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-25 12:00:00');

        // Any outbound HTTP would be a bug, so make one impossible to miss.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(float $calories, float $protein, float $carbs, float $fat): array
    {
        return [
            'meal_name' => 'Chicken and Rice',
            'meal_type' => 'lunch',
            'items' => [[
                'name' => 'Chicken and rice',
                'portion_amount' => 1,
                'portion_unit' => 'plate',
                'calories' => $calories,
                'protein' => $protein,
                'carbs' => $carbs,
                'fat' => $fat,
            ]],
        ];
    }

    public function test_saving_a_meal_returns_a_tip_without_calling_an_ai_provider(): void
    {
        // A real provider with no key: any AI call here would throw.
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.providers.anthropic.api_key', '');

        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->create([
            'calorie_target' => 2000,
            'protein_target' => 140,
            'carb_target' => 220,
            'fat_target' => 65,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->payload(520, 42, 55, 14))
            ->assertCreated()
            ->assertJsonPath('tip.tone', 'positive')
            ->assertJsonPath('tip.headline', 'Strong protein choice')
            ->assertJsonStructure(['tip' => ['headline', 'body', 'tone']]);
    }

    public function test_the_tip_quotes_the_days_real_remaining_figures(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->create([
            'calorie_target' => 2000,
            'protein_target' => 140,
            'carb_target' => 220,
            'fat_target' => 65,
        ]);

        Meal::factory()->for($user)->on('2026-08-25', 8)->withTotals(900, 30, 120, 30)->create();

        $body = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->payload(520, 42, 55, 14))
            ->assertCreated()
            ->json('tip.body');

        // 42g of a 140g target is 30%.
        $this->assertStringContainsString('30%', $body);
        // 900 + 520 = 1,420 consumed; protein 30 + 42 = 72 of 140, so 68 left.
        $this->assertStringContainsString('72g of 140g', $body);
        $this->assertStringContainsString('68g to go', $body);
        $this->assertStringContainsString('580 kcal left', $body);
    }

    public function test_the_tip_says_so_plainly_when_the_day_is_over_target(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->create([
            'calorie_target' => 1800,
            'protein_target' => 140,
            'carb_target' => 160,
            'fat_target' => 60,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->payload(2100, 60, 220, 80))
            ->assertCreated();

        $response->assertJsonPath('tip.tone', 'caution');
        $this->assertStringContainsString('300 kcal past', $response->json('tip.body'));
    }

    public function test_without_targets_the_tip_points_at_setting_them(): void
    {
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.providers.anthropic.api_key', '');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->payload(520, 42, 55, 14))
            ->assertCreated();

        $response->assertJsonPath('tip.tone', 'neutral');
        $this->assertStringContainsString('Set your daily calorie', $response->json('tip.body'));
    }

    public function test_the_tip_endpoint_returns_a_tip_for_an_owned_meal(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->create();
        $meal = Meal::factory()->for($user)->on('2026-08-25')->withTotals(600, 45, 60, 18)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/meals/{$meal->id}/tip")
            ->assertOk()
            ->assertJsonStructure(['data' => ['headline', 'body', 'tone']]);
    }

    public function test_the_tip_endpoint_requires_authentication_and_ownership(): void
    {
        $owner = User::factory()->create();
        $meal = Meal::factory()->for($owner)->create();

        $this->getJson("/api/meals/{$meal->id}/tip")->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/meals/{$meal->id}/tip")
            ->assertForbidden();
    }

    public function test_a_meal_logged_against_an_earlier_day_is_not_described_as_today(): void
    {
        $user = User::factory()->create();
        NutritionGoal::factory()->for($user)->create();

        $meal = Meal::factory()->for($user)->on('2026-08-20')->withTotals(700, 35, 70, 20)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/meals/{$meal->id}/tip")
            ->assertOk();

        $response->assertJsonPath('data.headline', 'Added to an earlier day');
        $this->assertStringContainsString('2026-08-20', $response->json('data.body'));
    }
}
