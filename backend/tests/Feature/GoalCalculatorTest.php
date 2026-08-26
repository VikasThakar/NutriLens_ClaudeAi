<?php

namespace Tests\Feature;

use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'age' => 34,
            'height_cm' => 178,
            'weight_kg' => 76.5,
            'activity_level' => 'moderate',
            'goal_type' => 'maintain_weight',
            'biological_sex' => 'male',
        ], $overrides);
    }

    public function test_the_calculator_requires_authentication(): void
    {
        $this->getJson('/api/nutrition-goals/calculator')->assertUnauthorized();
        $this->postJson('/api/nutrition-goals/calculate', $this->payload())->assertUnauthorized();
    }

    public function test_the_calculator_exposes_its_options_and_saved_inputs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/nutrition-goals/calculator')
            ->assertOk()
            ->assertJsonPath('data.formula', 'Mifflin-St Jeor')
            ->assertJsonCount(5, 'data.activity_levels')
            ->assertJsonCount(3, 'data.biological_sexes')
            ->assertJsonCount(4, 'data.goal_types')
            ->assertJsonPath('data.saved_inputs.age', null);
    }

    public function test_it_estimates_calories_with_mifflin_st_jeor(): void
    {
        $user = User::factory()->create();

        // 10(76.5) + 6.25(178) - 5(34) + 5 = 1712.5 -> 1713 BMR.
        // x 1.55 (moderate) = 2654.375 -> 2650 maintenance, maintain = no offset.
        $response = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', $this->payload())
            ->assertOk();

        $response->assertJsonPath('data.bmr', 1713)
            ->assertJsonPath('data.maintenance_calories', 2650)
            ->assertJsonPath('data.targets.calorie_target', 2650)
            ->assertJsonPath('data.calorie_adjustment', 0)
            ->assertJsonPath('data.formula', 'Mifflin-St Jeor')
            ->assertJsonPath('data.activity_multiplier', 1.55)
            ->assertJsonPath('data.sex_was_specified', true)
            ->assertJsonPath('data.is_estimate', true);
    }

    public function test_losing_weight_produces_a_deficit_and_gaining_a_surplus(): void
    {
        $user = User::factory()->create();

        $lose = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', $this->payload(['goal_type' => 'lose_weight']))
            ->assertOk();

        $gain = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', $this->payload(['goal_type' => 'build_muscle']))
            ->assertOk();

        $this->assertLessThan(
            $lose->json('data.maintenance_calories'),
            $lose->json('data.targets.calorie_target'),
        );
        $this->assertGreaterThan(
            $gain->json('data.maintenance_calories'),
            $gain->json('data.targets.calorie_target'),
        );

        // A deficit keeps protein high in grams per kilo.
        $this->assertGreaterThan(
            $gain->json('data.protein_per_kg'),
            $lose->json('data.protein_per_kg'),
        );
    }

    public function test_the_macro_split_accounts_for_the_calorie_target(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', $this->payload())
            ->assertOk()
            ->json('data');

        $fromMacros = ($data['targets']['protein_target'] * 4)
            + ($data['targets']['carb_target'] * 4)
            + ($data['targets']['fat_target'] * 9);

        // Within 2% of the calorie target: carbohydrate takes the remainder, so
        // the only drift is gram-level rounding.
        $this->assertLessThan(
            $data['targets']['calorie_target'] * 0.02,
            abs($fromMacros - $data['targets']['calorie_target']),
        );

        $this->assertSame(
            100,
            array_sum($data['macro_percent']),
            'The macro percentages should account for the whole calorie target.',
        );
    }

    public function test_biological_sex_is_optional_and_lands_between_the_two_constants(): void
    {
        $user = User::factory()->create();

        $female = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', $this->payload(['biological_sex' => 'female']))
            ->assertOk();

        $male = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', $this->payload(['biological_sex' => 'male']))
            ->assertOk();

        $omitted = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', collect($this->payload())->except('biological_sex')->all())
            ->assertOk();

        $omitted->assertJsonPath('data.biological_sex', 'unspecified')
            ->assertJsonPath('data.sex_was_specified', false);

        $this->assertGreaterThan($female->json('data.bmr'), $omitted->json('data.bmr'));
        $this->assertLessThan($male->json('data.bmr'), $omitted->json('data.bmr'));
    }

    public function test_the_estimate_is_clamped_to_a_sensible_floor(): void
    {
        $user = User::factory()->create();

        // A small, sedentary person on an 18% deficit: arithmetically under
        // 1,200 kcal, which is not a figure this app should suggest.
        $this->actingAs($user)->postJson('/api/nutrition-goals/calculate', $this->payload([
            'age' => 65,
            'height_cm' => 150,
            'weight_kg' => 45,
            'activity_level' => 'sedentary',
            'goal_type' => 'lose_weight',
            'biological_sex' => 'female',
        ]))
            ->assertOk()
            ->assertJsonPath('data.targets.calorie_target', 1200);
    }

    public function test_inputs_are_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/nutrition-goals/calculate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['age', 'height_cm', 'weight_kg', 'activity_level', 'goal_type']);

        $this->actingAs($user)->postJson('/api/nutrition-goals/calculate', $this->payload(['age' => 9]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('age');

        $this->actingAs($user)->postJson('/api/nutrition-goals/calculate', $this->payload(['height_cm' => 40]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('height_cm');

        $this->actingAs($user)->postJson('/api/nutrition-goals/calculate', $this->payload(['activity_level' => 'olympian']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('activity_level');
    }

    public function test_calculating_does_not_change_the_active_goal(): void
    {
        $user = User::factory()->create();
        $goal = NutritionGoal::factory()->for($user)->withCalorieTarget(1800)->create();

        $this->actingAs($user)->postJson('/api/nutrition-goals/calculate', $this->payload())->assertOk();

        // The user has to review and save before anything changes.
        $this->actingAs($user)->getJson('/api/nutrition-goals')
            ->assertOk()
            ->assertJsonPath('data.id', $goal->id)
            ->assertJsonPath('data.calorie_target', 1800);
    }

    public function test_the_inputs_are_remembered_for_next_time(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/nutrition-goals/calculate', $this->payload())->assertOk();

        $this->actingAs($user)->getJson('/api/nutrition-goals/calculator')
            ->assertOk()
            ->assertJsonPath('data.saved_inputs.age', 34)
            ->assertJsonPath('data.saved_inputs.height_cm', 178)
            ->assertJsonPath('data.saved_inputs.weight_kg', 76.5)
            ->assertJsonPath('data.saved_inputs.activity_level', 'moderate')
            ->assertJsonPath('data.saved_inputs.biological_sex', 'male');
    }

    public function test_a_calculated_estimate_can_be_adjusted_and_saved(): void
    {
        $user = User::factory()->create();

        $estimate = $this->actingAs($user)
            ->postJson('/api/nutrition-goals/calculate', $this->payload())
            ->assertOk()
            ->json('data');

        // The user nudges the calories down before saving — the whole point of
        // returning an estimate rather than applying one.
        $this->actingAs($user)->putJson('/api/nutrition-goals', [
            'goal_type' => 'maintain_weight',
            'calorie_target' => $estimate['targets']['calorie_target'] - 150,
            'protein_target' => $estimate['targets']['protein_target'],
            'carb_target' => $estimate['targets']['carb_target'],
            'fat_target' => $estimate['targets']['fat_target'],
            'source' => 'calculator',
            'estimated_maintenance_calories' => $estimate['maintenance_calories'],
        ])
            ->assertOk()
            ->assertJsonPath('data.calorie_target', $estimate['targets']['calorie_target'] - 150)
            ->assertJsonPath('data.source', 'calculator')
            ->assertJsonPath('data.estimated_maintenance_calories', $estimate['maintenance_calories']);

        $this->assertDatabaseHas('nutrition_goals', [
            'user_id' => $user->id,
            'source' => 'calculator',
            'is_active' => true,
        ]);
    }

    public function test_one_users_saved_metrics_are_not_visible_to_another(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->postJson('/api/nutrition-goals/calculate', $this->payload())->assertOk();

        $this->actingAs($user)->getJson('/api/nutrition-goals/calculator')
            ->assertOk()
            ->assertJsonPath('data.saved_inputs.age', null)
            ->assertJsonPath('data.saved_inputs.weight_kg', null);
    }
}
