<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\AI\Contracts\FoodNutritionEstimator;
use App\Services\AI\Contracts\MealVisionAnalyzer;
use App\Services\AI\Data\FoodQuery;
use App\Services\AI\Data\PreparedImage;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PartnerApiTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    private ApiKey $keyModel;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.provider', 'fake');

        $created = app(ApiKeyService::class)->create(User::factory()->create(), 'Test integration');
        $this->key = $created['plain_text'];
        $this->keyModel = $created['key'];

        // Limits are asserted explicitly in their own test; everywhere else a
        // leftover counter from another test must not cause a spurious 429.
        RateLimiter::clear('api-key:'.$this->keyModel->id);
    }

    /** @param array<string, mixed> $data */
    private function partnerPost(string $uri, array $data = [], ?string $key = null)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.($key ?? $this->key),
            'Accept' => 'application/json',
        ])->postJson($uri, $data);
    }

    private function partnerGet(string $uri, ?string $key = null)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.($key ?? $this->key),
            'Accept' => 'application/json',
        ])->getJson($uri);
    }

    /** @return array<int, array<string, mixed>> */
    private function foods(): array
    {
        return [
            ['name' => 'Chicken breast', 'portion_amount' => 150, 'portion_unit' => 'g'],
            ['name' => 'Brown rice', 'portion_amount' => 1, 'portion_unit' => 'cup'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Authentication                                                      */
    /* ------------------------------------------------------------------ */

    public function test_a_missing_api_key_is_rejected(): void
    {
        $this->postJson('/api/v1/nutrition/estimate', ['items' => $this->foods()])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'MISSING_API_KEY');
    }

    public function test_an_invalid_api_key_is_rejected(): void
    {
        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $this->foods()], 'nl_live_totallymadeupkeyvaluethatdoesnotexist12')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_API_KEY');
    }

    public function test_a_key_without_the_expected_prefix_is_rejected(): void
    {
        $this->partnerGet('/api/v1/ping', 'some-random-bearer-token')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_API_KEY');
    }

    public function test_a_revoked_api_key_is_rejected(): void
    {
        app(ApiKeyService::class)->revoke($this->keyModel);

        $this->partnerGet('/api/v1/ping')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'REVOKED_API_KEY');
    }

    public function test_an_expired_api_key_is_rejected(): void
    {
        $this->keyModel->forceFill(['expires_at' => now()->subDay()])->save();

        $this->partnerGet('/api/v1/ping')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'EXPIRED_API_KEY');
    }

    public function test_a_key_without_the_required_ability_is_forbidden(): void
    {
        $this->keyModel->forceFill(['abilities' => ['nutrition:estimate']])->save();

        $this->partnerPost('/api/v1/nutrition/analyze', [])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // The ability it does hold still works.
        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $this->foods()])
            ->assertOk();
    }

    public function test_the_x_api_key_header_is_also_accepted(): void
    {
        $this->withHeaders(['X-API-Key' => $this->key, 'Accept' => 'application/json'])
            ->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('data.authenticated', true);
    }

    public function test_ping_confirms_a_key_and_reports_limits(): void
    {
        $this->partnerGet('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.key.name', 'Test integration')
            ->assertJsonPath('data.key.prefix', $this->keyModel->key_prefix)
            ->assertJsonPath('data.limits.analyze_per_minute', 10)
            ->assertJsonStructure(['data' => ['portion_units']]);
    }

    public function test_using_a_key_records_when_it_was_last_used(): void
    {
        $this->assertNull($this->keyModel->last_used_at);

        $this->partnerGet('/api/v1/ping')->assertOk();

        $this->assertNotNull($this->keyModel->fresh()->last_used_at);
    }

    public function test_a_partner_key_cannot_reach_the_first_party_api(): void
    {
        // An API key is not a Sanctum token; the frontend endpoints must not
        // accept one.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->getJson('/api/api-keys')
            ->assertUnauthorized();
    }

    public function test_a_sanctum_token_cannot_reach_the_partner_api(): void
    {
        // And the reverse: a logged-in user's token is not an API key.
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/v1/ping')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_API_KEY');
    }

    /* ------------------------------------------------------------------ */
    /* Structured estimation                                               */
    /* ------------------------------------------------------------------ */

    public function test_structured_estimation_returns_nutrition_for_the_foods_sent(): void
    {
        $response = $this->partnerPost('/api/v1/nutrition/estimate', [
            'meal_name' => 'Post-gym lunch',
            'items' => $this->foods(),
        ])->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.meal_name', 'Post-gym lunch')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonStructure([
                'data' => [
                    'meal_name',
                    'confidence',
                    'totals' => ['calories', 'protein', 'carbs', 'fat'],
                    'items' => [['name', 'portion_amount', 'portion_unit', 'calories', 'protein', 'carbs', 'fat', 'confidence']],
                    'model' => ['provider', 'name'],
                    'disclaimer',
                ],
            ]);

        // The portions come back exactly as they were sent.
        $response->assertJsonPath('data.items.0.name', 'Chicken breast')
            ->assertJsonPath('data.items.0.portion_amount', 150)
            ->assertJsonPath('data.items.0.portion_unit', 'g')
            ->assertJsonPath('data.items.1.portion_unit', 'cup');

        // 150 g of chicken breast is around 250 kcal — the offline estimator is
        // a real nutrition table, not a random number.
        $calories = $response->json('data.items.0.calories');
        $this->assertGreaterThan(200, $calories);
        $this->assertLessThan(300, $calories);

        // Totals always agree with the items.
        $this->assertSame(
            array_sum(array_column($response->json('data.items'), 'calories')),
            $response->json('data.totals.calories'),
        );
    }

    public function test_the_response_scales_with_the_portion(): void
    {
        $small = $this->partnerPost('/api/v1/nutrition/estimate', [
            'items' => [['name' => 'Chicken breast', 'portion_amount' => 100, 'portion_unit' => 'g']],
        ])->assertOk()->json('data.totals.calories');

        $large = $this->partnerPost('/api/v1/nutrition/estimate', [
            'items' => [['name' => 'Chicken breast', 'portion_amount' => 300, 'portion_unit' => 'g']],
        ])->assertOk()->json('data.totals.calories');

        $this->assertEqualsWithDelta($small * 3, $large, 3);
    }

    public function test_an_unrecognised_food_is_flagged_rather_than_guessed_confidently(): void
    {
        $response = $this->partnerPost('/api/v1/nutrition/estimate', [
            'items' => [['name' => 'Grandmothers secret casserole', 'portion_amount' => 250, 'portion_unit' => 'g']],
        ])->assertOk();

        $this->assertLessThan(0.55, $response->json('data.items.0.confidence'));
        $this->assertNotNull($response->json('data.notes'));
    }

    public function test_structured_estimation_validates_every_field(): void
    {
        $this->partnerPost('/api/v1/nutrition/estimate', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['items']]]);

        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Missing name.
        $this->partnerPost('/api/v1/nutrition/estimate', [
            'items' => [['portion_amount' => 100, 'portion_unit' => 'g']],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['items.0.name']]]);

        // Zero and negative portions.
        foreach ([0, -5] as $amount) {
            $this->partnerPost('/api/v1/nutrition/estimate', [
                'items' => [['name' => 'Rice', 'portion_amount' => $amount, 'portion_unit' => 'g']],
            ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['items.0.portion_amount']]]);
        }

        // A unit outside the documented set.
        $this->partnerPost('/api/v1/nutrition/estimate', [
            'items' => [['name' => 'Rice', 'portion_amount' => 1, 'portion_unit' => 'dollop']],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['items.0.portion_unit']]]);

        // Non-numeric portion.
        $this->partnerPost('/api/v1/nutrition/estimate', [
            'items' => [['name' => 'Rice', 'portion_amount' => 'lots', 'portion_unit' => 'g']],
        ])->assertStatus(422);
    }

    public function test_too_many_items_are_rejected(): void
    {
        $items = array_fill(0, 21, ['name' => 'Rice', 'portion_amount' => 100, 'portion_unit' => 'g']);

        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $items])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['items']]]);
    }

    public function test_estimation_never_writes_a_meal(): void
    {
        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $this->foods()])->assertOk();

        // A partner request is a pure function of its input: nothing is logged
        // against the key owner's account.
        $this->assertDatabaseCount('meals', 0);
        $this->assertDatabaseCount('meal_items', 0);
        $this->assertDatabaseCount('meal_images', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Image analysis                                                      */
    /* ------------------------------------------------------------------ */

    private function image(int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image('meal.jpg', $width, $height);
    }

    public function test_image_analysis_returns_nutrition(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', ['image' => $this->image()])
            ->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'meal_name',
                    'confidence',
                    'totals' => ['calories', 'protein', 'carbs', 'fat'],
                    'items' => [['name', 'portion_amount', 'portion_unit', 'calories', 'protein', 'carbs', 'fat', 'confidence']],
                    'model' => ['provider', 'name'],
                    'disclaimer',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.items'));
        $this->assertSame(
            array_sum(array_column($response->json('data.items'), 'calories')),
            $response->json('data.totals.calories'),
        );
    }

    public function test_a_reference_is_echoed_back(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', [
                'image' => $this->image(),
                'reference' => 'order-12345',
            ])
            ->assertOk()
            ->assertJsonPath('data.reference', 'order-12345');
    }

    public function test_image_analysis_never_stores_the_upload(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', ['image' => $this->image()])
            ->assertOk();

        // Unlike the first-party flow, a partner upload is not retained.
        $this->assertDatabaseCount('meal_images', 0);
        $this->assertDatabaseCount('meals', 0);
    }

    public function test_a_missing_image_is_rejected(): void
    {
        $this->partnerPost('/api/v1/nutrition/analyze', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['image']]]);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', [
                'image' => UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_renamed_file_pretending_to_be_an_image_is_rejected(): void
    {
        // Correct extension and mime type, but the bytes are not an image.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', [
                'image' => UploadedFile::fake()->createWithContent('payload.jpg', 'this is not an image'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_an_unsupported_image_format_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', [
                'image' => UploadedFile::fake()->image('photo.gif', 400, 400),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $file = UploadedFile::fake()->image('huge.jpg', 4000, 3000)->size(13000);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', ['image' => $file])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['image']]]);
    }

    public function test_a_tiny_image_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', ['image' => $this->image(20, 20)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /* ------------------------------------------------------------------ */
    /* Downstream failures                                                 */
    /* ------------------------------------------------------------------ */

    public function test_an_unavailable_ai_provider_returns_503(): void
    {
        $this->app->bind(FoodNutritionEstimator::class, fn () => new class implements FoodNutritionEstimator
        {
            public function estimate(FoodQuery $query): array
            {
                throw new AiUnavailableException('Down.');
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function modelName(): string
            {
                return 'stub';
            }
        });

        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $this->foods()])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'AI_UNAVAILABLE');
    }

    public function test_a_missing_ai_key_returns_a_configuration_error(): void
    {
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.providers.anthropic.api_key', '');

        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $this->foods()])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'AI_NOT_CONFIGURED');
    }

    public function test_an_ai_that_returns_the_wrong_number_of_items_is_rejected(): void
    {
        // Asked about two foods, answers about one. That is not an answer to the
        // question the partner asked.
        $this->app->bind(FoodNutritionEstimator::class, fn () => new class implements FoodNutritionEstimator
        {
            public function estimate(FoodQuery $query): array
            {
                return [
                    'meal_name' => 'Partial answer',
                    'confidence' => 0.9,
                    'notes' => '',
                    'items' => [[
                        'name' => 'Chicken breast',
                        'portion_amount' => 150,
                        'portion_unit' => 'g',
                        'calories' => 248,
                        'protein' => 46,
                        'carbs' => 0,
                        'fat' => 5,
                        'confidence' => 0.9,
                    ]],
                ];
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function modelName(): string
            {
                return 'stub';
            }
        });

        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $this->foods()])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'AI_INVALID_RESPONSE');
    }

    public function test_a_malformed_ai_response_is_rejected(): void
    {
        $this->app->bind(FoodNutritionEstimator::class, fn () => new class implements FoodNutritionEstimator
        {
            public function estimate(FoodQuery $query): array
            {
                return ['nonsense' => true];
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function modelName(): string
            {
                return 'stub';
            }
        });

        $this->partnerPost('/api/v1/nutrition/estimate', ['items' => $this->foods()])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'AI_INVALID_RESPONSE');
    }

    public function test_a_vision_failure_returns_503_in_the_partner_envelope(): void
    {
        $this->app->bind(MealVisionAnalyzer::class, fn () => new class implements MealVisionAnalyzer
        {
            public function analyze(PreparedImage $image): array
            {
                throw new AiUnavailableException('Down.');
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function modelName(): string
            {
                return 'stub';
            }
        });

        $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', ['image' => $this->image()])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'AI_UNAVAILABLE');
    }

    /* ------------------------------------------------------------------ */
    /* Routing and shape                                                   */
    /* ------------------------------------------------------------------ */

    public function test_an_unknown_v1_endpoint_answers_in_the_partner_envelope(): void
    {
        $this->partnerGet('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_the_wrong_method_answers_in_the_partner_envelope(): void
    {
        $this->partnerGet('/api/v1/nutrition/estimate')
            ->assertStatus(405)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_rate_limits_are_applied_per_key_not_per_ip(): void
    {
        $service = app(ApiKeyService::class);
        $second = $service->create(User::factory()->create(), 'Another partner');

        RateLimiter::clear('api-key:'.$second['key']->id);

        // Exhaust the first key's per-minute image budget (10).
        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
                ->post('/api/v1/nutrition/analyze', ['image' => $this->image()])
                ->assertOk();
        }

        $limited = $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', ['image' => $this->image()]);

        $limited->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'RATE_LIMITED');

        $this->assertNotNull($limited->headers->get('Retry-After'), 'A 429 must tell the caller when to retry.');

        // The same IP, a different key: unaffected. This is what proves the
        // bucket is the key rather than the address.
        $this->withHeaders(['Authorization' => 'Bearer '.$second['plain_text'], 'Accept' => 'application/json'])
            ->post('/api/v1/nutrition/analyze', ['image' => $this->image()])
            ->assertOk();
    }
}
