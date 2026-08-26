<?php

namespace Tests\Feature;

use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NutritionGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_endpoints_require_authentication(): void
    {
        $this->getJson('/api/nutrition-goals')->assertUnauthorized();
        $this->putJson('/api/nutrition-goals', [])->assertUnauthorized();
        $this->postJson('/api/onboarding', [])->assertUnauthorized();
        $this->getJson('/api/dashboard/today')->assertUnauthorized();
    }

    public function test_a_new_user_has_no_active_goal(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/nutrition-goals')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_onboarding_stores_the_goal_and_marks_the_account_onboarded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', [
                'goal_type' => 'lose_weight',
                'calorie_target' => 1900,
                'protein_target' => 150,
                'carb_target' => 170,
                'fat_target' => 62,
            ])
            ->assertOk()
            ->assertJsonPath('data.has_onboarded', true)
            ->assertJsonPath('data.nutrition_goal.goal_type', 'lose_weight')
            ->assertJsonPath('data.nutrition_goal.calorie_target', 1900);

        $this->assertNotNull($user->fresh()->onboarded_at);
        $this->assertDatabaseHas('nutrition_goals', [
            'user_id' => $user->id,
            'goal_type' => 'lose_weight',
            'calorie_target' => 1900,
            'is_active' => true,
        ]);
    }

    public function test_onboarding_falls_back_to_the_recommended_targets_when_they_are_skipped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', ['goal_type' => 'build_muscle'])
            ->assertOk()
            // Defaults come from GoalType::defaultTargets().
            ->assertJsonPath('data.nutrition_goal.calorie_target', 2800)
            ->assertJsonPath('data.nutrition_goal.protein_target', 190);
    }

    public function test_onboarding_records_targets_that_came_from_the_calculator(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', [
                'goal_type' => 'build_muscle',
                'calorie_target' => 2750,
                'protein_target' => 185,
                'carb_target' => 310,
                'fat_target' => 82,
                'source' => 'calculator',
                'estimated_maintenance_calories' => 2450,
            ])
            ->assertOk()
            ->assertJsonPath('data.nutrition_goal.source', 'calculator')
            ->assertJsonPath(
                'data.nutrition_goal.estimated_maintenance_calories',
                2450,
            );

        $this->assertDatabaseHas('nutrition_goals', [
            'user_id' => $user->id,
            'source' => 'calculator',
            'estimated_maintenance_calories' => 2450,
            'is_active' => true,
        ]);
    }

    public function test_onboarding_without_a_source_is_recorded_as_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', ['goal_type' => 'lose_weight'])
            ->assertOk()
            ->assertJsonPath('data.nutrition_goal.source', 'onboarding')
            ->assertJsonPath(
                'data.nutrition_goal.estimated_maintenance_calories',
                null,
            );
    }

    public function test_onboarding_rejects_an_unknown_source(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/onboarding', [
                'goal_type' => 'lose_weight',
                'source' => 'a_dream',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');
    }

    public function test_onboarding_requires_a_valid_goal_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal_type');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', ['goal_type' => 'become_a_cyclist'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal_type');
    }

    public function test_updating_goals_retires_the_previous_one_instead_of_overwriting_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', ['goal_type' => 'maintain_weight'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/nutrition-goals', [
                'goal_type' => 'build_muscle',
                'calorie_target' => 3000,
                'protein_target' => 200,
                'carb_target' => 340,
                'fat_target' => 90,
            ])
            ->assertOk()
            ->assertJsonPath('data.goal_type', 'build_muscle')
            ->assertJsonPath('data.calorie_target', 3000);

        // Two rows of history, exactly one of them active.
        $this->assertSame(2, NutritionGoal::where('user_id', $user->id)->count());
        $this->assertSame(1, NutritionGoal::where('user_id', $user->id)->where('is_active', true)->count());
    }

    public function test_goal_targets_are_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/nutrition-goals', [
                'goal_type' => 'lose_weight',
                'calorie_target' => 100,      // below the 800 minimum
                'protein_target' => -5,       // negative
                'carb_target' => 200,
                'fat_target' => 70,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calorie_target', 'protein_target']);
    }

    public function test_a_user_cannot_see_another_users_goal(): void
    {
        $alex = User::factory()->create();
        $sam = User::factory()->create();

        $this->actingAs($sam, 'sanctum')
            ->postJson('/api/onboarding', ['goal_type' => 'build_muscle'])
            ->assertOk();

        // Alex asks for "the" goal and gets his own — which is none.
        $this->actingAs($alex, 'sanctum')
            ->getJson('/api/nutrition-goals')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_the_today_dashboard_reports_zero_intake_for_a_new_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/onboarding', ['goal_type' => 'lose_weight'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/today')
            ->assertOk()
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.consumed.calories', 0)
            ->assertJsonPath('data.goal.goal_type', 'lose_weight')
            // Nothing eaten yet, so the whole target remains.
            ->assertJsonPath('data.remaining.calories', 1800)
            ->assertJsonPath('data.meals', []);
    }

    public function test_the_today_dashboard_rejects_a_malformed_date(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/dashboard/today?date=25-08-2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }
}
