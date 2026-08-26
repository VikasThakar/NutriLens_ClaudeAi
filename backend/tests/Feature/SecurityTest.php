<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The security properties that are easy to break by accident during a refactor,
 * pinned as tests so a regression fails the suite rather than shipping.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.provider', 'fake');
    }

    /* ------------------------------------------------------------------ */
    /* Authentication coverage                                             */
    /* ------------------------------------------------------------------ */

    public function test_every_first_party_endpoint_requires_authentication(): void
    {
        $endpoints = [
            ['get', '/api/user'],
            ['patch', '/api/user'],
            ['get', '/api/nutrition-goals'],
            ['put', '/api/nutrition-goals'],
            ['get', '/api/nutrition-goals/history'],
            ['get', '/api/nutrition-goals/calculator'],
            ['post', '/api/nutrition-goals/calculate'],
            ['post', '/api/onboarding'],
            ['get', '/api/dashboard/today'],
            ['get', '/api/history/day'],
            ['get', '/api/history/calendar'],
            ['get', '/api/analytics'],
            ['get', '/api/streak'],
            ['get', '/api/insights'],
            ['get', '/api/insights/current'],
            ['post', '/api/insights/generate'],
            ['get', '/api/api-keys'],
            ['post', '/api/api-keys'],
            ['get', '/api/meals'],
            ['post', '/api/meals'],
            ['post', '/api/meals/analyze'],
            ['post', '/api/logout'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $this->{$method.'Json'}($uri)
                ->assertUnauthorized();
        }
    }

    public function test_logout_revokes_only_the_token_that_was_used(): void
    {
        $user = User::factory()->create();

        $phone = $user->createToken("phone");
        $laptop = $user->createToken("laptop");

        $this->withHeader("Authorization", "Bearer {$phone->plainTextToken}")
            ->postJson("/api/logout")
            ->assertOk();

        // The revoked token row is gone; the other device's survives.
        $this->assertDatabaseMissing("personal_access_tokens", ["id" => $phone->accessToken->id]);
        $this->assertDatabaseHas("personal_access_tokens", ["id" => $laptop->accessToken->id]);

        // Laravel's RequestGuard memoises the resolved user for the lifetime of
        // the test, so it has to be cleared before asserting on a later request.
        $this->app["auth"]->forgetGuards();

        $this->withHeader("Authorization", "Bearer {$phone->plainTextToken}")
            ->getJson("/api/user")
            ->assertUnauthorized();

        $this->app["auth"]->forgetGuards();

        $this->withHeader("Authorization", "Bearer {$laptop->plainTextToken}")
            ->getJson("/api/user")
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Mass assignment                                                     */
    /* ------------------------------------------------------------------ */

    public function test_a_meal_cannot_be_created_on_behalf_of_another_user(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/meals', [
            // Both of these are attempts to write outside the caller's own data.
            'user_id' => $victim->id,
            'id' => 9999,
            'meal_name' => 'Injected',
            'meal_type' => 'lunch',
            'items' => [
                ['name' => 'Rice', 'portion_amount' => 100, 'portion_unit' => 'g', 'calories' => 130, 'protein' => 3, 'carbs' => 28, 'fat' => 0.3],
            ],
        ])->assertCreated();

        $meal = Meal::query()->sole();

        $this->assertSame($user->id, $meal->user_id, 'The meal must belong to the caller.');
        $this->assertNotSame(9999, $meal->id);

        // And it is invisible to the user it was aimed at.
        $this->actingAs($victim)->getJson('/api/meals')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame($meal->id, $response->json('data.id'));
    }

    public function test_a_goal_cannot_be_written_for_another_user(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->putJson('/api/nutrition-goals', [
            'user_id' => $victim->id,
            'goal_type' => 'lose_weight',
            'calorie_target' => 1800,
            'protein_target' => 140,
            'carb_target' => 160,
            'fat_target' => 60,
        ])->assertOk();

        $this->assertSame(0, $victim->nutritionGoals()->count());
        $this->assertSame(1, $user->nutritionGoals()->count());
    }

    public function test_a_profile_update_cannot_change_the_account_it_belongs_to(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        $victim = User::factory()->create(['name' => 'Victim']);

        $this->actingAs($user)->patchJson('/api/user', [
            'id' => $victim->id,
            'name' => 'Renamed',
        ])->assertOk();

        $this->assertSame('Renamed', $user->fresh()->name);
        $this->assertSame('Victim', $victim->fresh()->name);
    }

    public function test_an_api_key_cannot_be_created_for_another_user(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->postJson('/api/api-keys', [
            'user_id' => $victim->id,
            'name' => 'Injected key',
        ])->assertCreated();

        $this->assertSame(1, $user->apiKeys()->count());
        $this->assertSame(0, $victim->apiKeys()->count());
    }

    public function test_a_revoked_key_cannot_be_reactivated_through_the_api(): void
    {
        $user = User::factory()->create();
        $key = ApiKey::factory()->for($user)->revoked()->create();

        // There is no endpoint that clears revoked_at, and the update paths do
        // not accept it. The key stays dead.
        $this->actingAs($user)->postJson('/api/api-keys', [
            'name' => 'New key',
            'revoked_at' => null,
            'id' => $key->id,
        ])->assertCreated();

        $this->assertNotNull($key->fresh()->revoked_at);
    }

    /* ------------------------------------------------------------------ */
    /* Secret handling                                                     */
    /* ------------------------------------------------------------------ */

    public function test_no_response_ever_contains_a_key_hash_or_password_hash(): void
    {
        $user = User::factory()->create();
        $created = app(ApiKeyService::class)->create($user, 'Leak check');

        $responses = [
            $this->actingAs($user)->getJson('/api/user'),
            $this->actingAs($user)->getJson('/api/api-keys'),
            $this->withHeader('Authorization', 'Bearer '.$created['plain_text'])
                ->getJson('/api/v1/ping'),
        ];

        foreach ($responses as $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString($created['key']->key_hash, $body);
            $this->assertStringNotContainsString('key_hash', $body);
            $this->assertStringNotContainsString($user->password, $body);
            $this->assertStringNotContainsString('password', $body);
        }
    }

    public function test_the_partner_api_never_reveals_the_key_owners_identity(): void
    {
        $owner = User::factory()->create(['name' => 'Alice Owner', 'email' => 'alice@example.test']);
        $created = app(ApiKeyService::class)->create($owner, 'Partner key');

        $body = $this->withHeader('Authorization', 'Bearer '.$created['plain_text'])
            ->postJson('/api/v1/nutrition/estimate', [
                'items' => [['name' => 'Rice', 'portion_amount' => 100, 'portion_unit' => 'g']],
            ])
            ->assertOk()
            ->getContent();

        // A partner holds a key, not an identity. Nothing about the account it
        // belongs to should appear in a nutrition response.
        $this->assertStringNotContainsString('Alice Owner', $body);
        $this->assertStringNotContainsString('alice@example.test', $body);
        $this->assertStringNotContainsString((string) $owner->id, (string) json_decode($body, true)['data']['meal_name']);
    }

    public function test_an_internal_error_does_not_leak_detail_to_a_partner(): void
    {
        $created = app(ApiKeyService::class)->create(User::factory()->create(), 'Error check');

        // Force an unexpected failure deep in the stack.
        $this->app->bind(\App\Services\AI\FoodEstimationService::class, function () {
            throw new \RuntimeException('Database password is hunter2 at /var/secret/path');
        });

        $response = $this->withHeader('Authorization', 'Bearer '.$created['plain_text'])
            ->postJson('/api/v1/nutrition/estimate', [
                'items' => [['name' => 'Rice', 'portion_amount' => 100, 'portion_unit' => 'g']],
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $body = $response->getContent();

        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('/var/secret/path', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }

    /* ------------------------------------------------------------------ */
    /* Uploads                                                             */
    /* ------------------------------------------------------------------ */

    public function test_an_uploaded_file_is_stored_outside_the_public_root(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/meals/analyze', [
            'image' => UploadedFile::fake()->image('meal.jpg', 400, 400),
        ])->assertOk();

        $image = \App\Models\MealImage::query()->sole();

        // The private disk, not `public/`. A photo must never be fetchable by
        // guessing a path.
        $this->assertSame('local', $image->disk);
        $this->assertStringStartsWith("meals/{$user->id}/", $image->path);
        $this->assertTrue(Storage::disk('local')->exists($image->path));

        // The stored name is generated, never the client's filename.
        $this->assertStringNotContainsString('meal.jpg', $image->path);
    }

    public function test_a_traversal_filename_cannot_escape_the_upload_directory(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/meals/analyze', [
            'image' => UploadedFile::fake()->image('../../../../evil.jpg', 400, 400),
        ])->assertOk();

        $image = \App\Models\MealImage::query()->sole();

        $this->assertStringStartsWith("meals/{$user->id}/", $image->path);
        $this->assertStringNotContainsString('..', $image->path);
    }

    public function test_a_photo_is_not_reachable_without_a_valid_signature(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/meals/analyze', [
            'image' => UploadedFile::fake()->image('meal.jpg', 400, 400),
        ])->assertOk();

        $image = \App\Models\MealImage::query()->sole();

        // Unsigned.
        $this->get("/api/meal-images/{$image->id}/file")->assertForbidden();

        // Signed with a bogus signature.
        $this->get("/api/meal-images/{$image->id}/file?signature=deadbeef&expires=9999999999")
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Cross-user reads                                                    */
    /* ------------------------------------------------------------------ */

    public function test_no_list_endpoint_returns_another_users_records(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        NutritionGoal::factory()->for($other)->create();
        Meal::factory()->for($other)->count(3)->create();
        ApiKey::factory()->for($other)->create();
        \App\Models\WeeklyInsight::factory()->for($other)->create();

        $this->actingAs($user)->getJson('/api/meals')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/api-keys')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/insights')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/nutrition-goals')->assertOk()->assertJsonPath('data', null);
        $this->actingAs($user)->getJson('/api/nutrition-goals/history')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/dashboard/today')
            ->assertOk()
            ->assertJsonPath('data.meal_count', 0)
            ->assertJsonPath('data.has_any_meals', false);
    }

    public function test_another_users_meal_image_cannot_be_claimed_when_saving(): void
    {
        $owner = User::factory()->create();
        $thief = User::factory()->create();

        $this->actingAs($owner)->post('/api/meals/analyze', [
            'image' => UploadedFile::fake()->image('meal.jpg', 400, 400),
        ])->assertOk();

        $image = \App\Models\MealImage::query()->sole();

        $this->actingAs($thief)->postJson('/api/meals', [
            'meal_name' => 'Stolen photo',
            'meal_type' => 'lunch',
            'meal_image_id' => $image->id,
            'items' => [
                ['name' => 'Rice', 'portion_amount' => 100, 'portion_unit' => 'g', 'calories' => 130, 'protein' => 3, 'carbs' => 28, 'fat' => 0.3],
            ],
        ])->assertCreated();

        // The meal saves, but without the photo — the image stays with its owner.
        $this->assertSame($owner->id, $image->fresh()->user_id);
        $this->assertNull($image->fresh()->meal_id);
    }
}
