<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\MealSource;
use App\Models\Meal;
use App\Models\MealImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MealTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function aiMealPayload(array $overrides = []): array
    {
        return array_merge([
            'meal_name' => 'Chicken Rice Bowl',
            'meal_type' => 'lunch',
            'source' => 'ai_photo',
            'ai_confidence' => 0.88,
            'ai_provider' => 'fake',
            'ai_model' => 'nutrilens-fake-vision',
            'items' => [
                [
                    'name' => 'Grilled chicken breast',
                    'portion_amount' => 165,
                    'portion_unit' => 'g',
                    'calories' => 272,
                    'protein' => 50.5,
                    'carbs' => 0,
                    'fat' => 6,
                    'base_portion_amount' => 165,
                    'base_calories' => 272,
                    'base_protein' => 50.5,
                    'base_carbs' => 0,
                    'base_fat' => 6,
                    'confidence' => 0.93,
                    'is_ai_generated' => true,
                ],
                [
                    'name' => 'Brown rice',
                    'portion_amount' => 1,
                    'portion_unit' => 'cup',
                    'calories' => 216,
                    'protein' => 5,
                    'carbs' => 45,
                    'fat' => 1.8,
                    'confidence' => 0.89,
                    'is_ai_generated' => true,
                ],
            ],
        ], $overrides);
    }

    public function test_meal_endpoints_require_authentication(): void
    {
        $this->getJson('/api/meals')->assertUnauthorized();
        $this->postJson('/api/meals', [])->assertUnauthorized();
        $this->getJson('/api/meals/1')->assertUnauthorized();
        $this->putJson('/api/meals/1', [])->assertUnauthorized();
        $this->deleteJson('/api/meals/1')->assertUnauthorized();
    }

    public function test_it_saves_a_reviewed_ai_meal_with_its_items(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload());

        $response->assertCreated()
            ->assertJsonPath('data.meal_name', 'Chicken Rice Bowl')
            ->assertJsonPath('data.source', 'ai_photo')
            ->assertJsonPath('data.ai_confidence', 0.88)
            // Totals are computed server-side from the items, not trusted from
            // the client.
            ->assertJsonPath('data.totals.calories', 488)
            ->assertJsonPath('data.totals.protein', 55.5);

        $this->assertDatabaseHas('meals', ['user_id' => $user->id, 'meal_name' => 'Chicken Rice Bowl']);
        $this->assertSame(2, Meal::firstOrFail()->items()->count());
    }

    public function test_it_saves_a_manual_meal_with_no_ai_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', [
                'meal_name' => 'Overnight Oats',
                'meal_type' => 'breakfast',
                'items' => [
                    ['name' => 'Rolled oats', 'portion_amount' => 80, 'portion_unit' => 'g', 'calories' => 303, 'protein' => 10.6, 'carbs' => 54, 'fat' => 5.5],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.source', MealSource::Manual->value)
            ->assertJsonPath('data.ai_confidence', null)
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.totals.calories', 303);
    }

    public function test_it_persists_the_ai_baseline_and_locked_macros_for_later_scaling(): void
    {
        $user = User::factory()->create();

        $payload = $this->aiMealPayload([
            'items' => [
                [
                    'name' => 'Grilled chicken breast',
                    // The user doubled the portion, so macros were rescaled…
                    'portion_amount' => 330,
                    'portion_unit' => 'g',
                    'calories' => 544,
                    'protein' => 101,
                    'carbs' => 0,
                    'fat' => 12,
                    // …but the baseline stays at the original estimate.
                    'base_portion_amount' => 165,
                    'base_calories' => 272,
                    'base_protein' => 50.5,
                    'base_carbs' => 0,
                    'base_fat' => 6,
                    'confidence' => 0.93,
                    'is_ai_generated' => true,
                    'was_edited' => true,
                    'locked_macros' => ['calories'],
                ],
            ],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.base_portion_amount', 165)
            ->assertJsonPath('data.items.0.base_calories', 272)
            ->assertJsonPath('data.items.0.portion_amount', 330)
            ->assertJsonPath('data.items.0.locked_macros', ['calories'])
            ->assertJsonPath('data.items.0.was_edited', true);
    }

    public function test_it_rejects_an_unknown_locked_macro(): void
    {
        $payload = $this->aiMealPayload();
        $payload['items'][0]['locked_macros'] = ['calories', 'vitamins'];

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/meals', $payload)
            ->assertStatus(422)
            // 'vitamins' is the second entry, so that is the key that fails.
            ->assertJsonValidationErrors('items.0.locked_macros.1');
    }

    public function test_it_requires_a_name_a_type_and_at_least_one_item(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/meals', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meal_name', 'meal_type', 'items']);
    }

    public function test_it_rejects_an_invalid_meal_type_and_a_zero_portion(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/meals', [
                'meal_name' => 'Brunch thing',
                'meal_type' => 'brunch',
                'items' => [
                    ['name' => 'Toast', 'portion_amount' => 0, 'portion_unit' => 'slice', 'calories' => 80, 'protein' => 3, 'carbs' => 14, 'fat' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meal_type', 'items.0.portion_amount']);
    }

    public function test_saving_a_meal_claims_the_uploaded_photo(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $image = MealImage::create([
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => 'meals/'.$user->id.'/photo.jpg',
            'analysis_status' => AnalysisStatus::Completed,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload(['meal_image_id' => $image->id]));

        $response->assertCreated();
        $this->assertNotNull($response->json('data.image_url'));
        $this->assertSame($response->json('data.id'), $image->fresh()->meal_id);
    }

    public function test_a_user_cannot_claim_another_users_photo(): void
    {
        Storage::fake('local');

        $alex = User::factory()->create();
        $sam = User::factory()->create();

        $samsImage = MealImage::create([
            'user_id' => $sam->id,
            'disk' => 'local',
            'path' => 'meals/'.$sam->id.'/photo.jpg',
            'analysis_status' => AnalysisStatus::Completed,
        ]);

        $response = $this->actingAs($alex, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload(['meal_image_id' => $samsImage->id]));

        // The meal saves, but silently without the photo it did not own.
        $response->assertCreated()->assertJsonPath('data.image_url', null);
        $this->assertNull($samsImage->fresh()->meal_id);
    }

    public function test_the_index_is_scoped_to_the_caller(): void
    {
        $alex = User::factory()->create();
        $sam = User::factory()->create();

        $this->actingAs($alex, 'sanctum')->postJson('/api/meals', $this->aiMealPayload())->assertCreated();
        $this->actingAs($sam, 'sanctum')->postJson('/api/meals', $this->aiMealPayload(['meal_name' => "Sam's lunch"]))->assertCreated();

        $this->actingAs($alex, 'sanctum')
            ->getJson('/api/meals')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.meal_name', 'Chicken Rice Bowl');
    }

    public function test_the_index_can_filter_by_meal_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/meals', $this->aiMealPayload())->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/meals', $this->aiMealPayload([
            'meal_name' => 'Porridge',
            'meal_type' => 'breakfast',
        ]))->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/meals?meal_type=breakfast')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.meal_name', 'Porridge');
    }

    public function test_a_user_can_update_their_meal_and_totals_are_recomputed(): void
    {
        $user = User::factory()->create();

        $mealId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload())
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/meals/{$mealId}", [
                'meal_name' => 'Chicken & Rice',
                'meal_type' => 'dinner',
                'items' => [
                    ['name' => 'Grilled chicken breast', 'portion_amount' => 200, 'portion_unit' => 'g', 'calories' => 330, 'protein' => 61, 'carbs' => 0, 'fat' => 7.3],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.meal_name', 'Chicken & Rice')
            ->assertJsonPath('data.meal_type', 'dinner')
            ->assertJsonPath('data.totals.calories', 330)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_updating_cannot_empty_a_meal(): void
    {
        $user = User::factory()->create();

        $mealId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload())
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/meals/{$mealId}", ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_a_user_cannot_read_update_or_delete_another_users_meal(): void
    {
        $alex = User::factory()->create();
        $sam = User::factory()->create();

        $mealId = $this->actingAs($alex, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload())
            ->json('data.id');

        $this->actingAs($sam, 'sanctum')->getJson("/api/meals/{$mealId}")->assertForbidden();
        $this->actingAs($sam, 'sanctum')->putJson("/api/meals/{$mealId}", ['meal_name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($sam, 'sanctum')->deleteJson("/api/meals/{$mealId}")->assertForbidden();

        $this->assertSame('Chicken Rice Bowl', Meal::findOrFail($mealId)->meal_name);
    }

    public function test_deleting_a_meal_removes_it_from_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/onboarding', ['goal_type' => 'lose_weight'])->assertOk();

        $mealId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload())
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/today')
            ->assertJsonPath('data.meal_count', 1)
            ->assertJsonPath('data.consumed.calories', 488);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/meals/{$mealId}")->assertOk();

        $this->actingAs($user, 'sanctum')->getJson("/api/meals/{$mealId}")->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/today')
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.consumed.calories', 0);
    }

    public function test_the_dashboard_groups_meals_by_type_with_per_group_totals(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/meals', $this->aiMealPayload())->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/meals', [
            'meal_name' => 'Porridge',
            'meal_type' => 'breakfast',
            'items' => [
                ['name' => 'Oats', 'portion_amount' => 60, 'portion_unit' => 'g', 'calories' => 228, 'protein' => 8, 'carbs' => 40, 'fat' => 4],
            ],
        ])->assertCreated();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/today')->assertOk();

        // All four buckets are always present, in a stable order.
        $response->assertJsonCount(4, 'data.groups')
            ->assertJsonPath('data.groups.0.meal_type', 'breakfast')
            ->assertJsonPath('data.groups.0.label', 'Breakfast')
            ->assertJsonPath('data.groups.0.meal_count', 1)
            ->assertJsonPath('data.groups.0.totals.calories', 228)
            ->assertJsonPath('data.groups.1.meal_type', 'lunch')
            ->assertJsonPath('data.groups.1.totals.calories', 488)
            ->assertJsonPath('data.groups.3.label', 'Snacks')
            ->assertJsonPath('data.groups.3.meal_count', 0);
    }

    public function test_a_meal_photo_is_only_reachable_through_a_signed_url(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Storage::disk('local')->put("meals/{$user->id}/photo.jpg", 'binary-image-data');

        $image = MealImage::create([
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => "meals/{$user->id}/photo.jpg",
            'mime_type' => 'image/jpeg',
            'analysis_status' => AnalysisStatus::Completed,
        ]);

        $signed = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals', $this->aiMealPayload(['meal_image_id' => $image->id]))
            ->json('data.image_url');

        $this->get($signed)->assertOk();

        // Unsigned, and tampered, are both refused.
        $this->get("/api/meal-images/{$image->id}/file")->assertForbidden();
        $this->get($signed.'x')->assertForbidden();
    }
}
