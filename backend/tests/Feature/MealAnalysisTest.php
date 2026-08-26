<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\MealImage;
use App\Models\User;
use App\Services\AI\Contracts\MealVisionAnalyzer;
use App\Services\AI\Data\PreparedImage;
use App\Services\AI\Exceptions\AiUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MealAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('ai.provider', 'fake');
        config()->set('ai.providers.fake.delay_ms', 0);
    }

    private function photo(int $width = 900, int $height = 700): UploadedFile
    {
        return UploadedFile::fake()->image('meal.jpg', $width, $height);
    }

    public function test_analysis_requires_authentication(): void
    {
        $this->postJson('/api/meals/analyze', ['image' => $this->photo()])
            ->assertUnauthorized();
    }

    public function test_it_analyses_a_photo_into_multiple_food_items(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'analysis' => [
                        'meal_name',
                        'confidence',
                        'notes',
                        'items' => [['name', 'portion_amount', 'portion_unit', 'calories', 'protein', 'carbs', 'fat', 'confidence']],
                        'totals' => ['calories', 'protein', 'carbs', 'fat'],
                        'provider',
                        'model',
                    ],
                    'meal_image' => ['id', 'url'],
                ],
            ]);

        // The whole point of the feature: one photo, several foods.
        $this->assertGreaterThan(1, count($response->json('data.analysis.items')));

        $confidence = $response->json('data.analysis.confidence');
        $this->assertGreaterThanOrEqual(0, $confidence);
        $this->assertLessThanOrEqual(1, $confidence);
    }

    public function test_the_uploaded_photo_is_stored_and_marked_analysed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()])
            ->assertOk();

        $image = MealImage::firstOrFail();

        $this->assertSame($user->id, $image->user_id);
        $this->assertNull($image->meal_id, 'The image should not be attached to a meal yet.');
        $this->assertSame(AnalysisStatus::Completed, $image->analysis_status);
        $this->assertNotNull($image->analyzed_at);
        Storage::disk('local')->assertExists($image->path);
    }

    public function test_meal_totals_match_the_sum_of_the_items(): void
    {
        $user = User::factory()->create();

        $analysis = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()])
            ->json('data.analysis');

        $expected = array_sum(array_column($analysis['items'], 'calories'));

        $this->assertSame((int) round($expected), $analysis['totals']['calories']);
    }

    public function test_it_rejects_a_file_that_is_not_an_image(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', [
                'image' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_it_rejects_a_disallowed_image_format(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', [
                'image' => UploadedFile::fake()->create('meal.gif', 500, 'image/gif'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_it_rejects_an_oversized_image(): void
    {
        $user = User::factory()->create();

        $oversized = UploadedFile::fake()->create('huge.jpg', 20_000, 'image/jpeg');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $oversized])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_it_rejects_an_image_that_is_too_small_to_analyse(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo(30, 30)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_it_requires_an_image(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/meals/analyze', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_a_provider_failure_returns_503_and_keeps_the_photo(): void
    {
        $user = User::factory()->create();

        // Swap in an analyzer that always fails, to exercise the error path the
        // UI offers "retry / another photo / enter manually" against.
        $this->swap(MealVisionAnalyzer::class, new class implements MealVisionAnalyzer
        {
            public function analyze(PreparedImage $image): array
            {
                throw new AiUnavailableException('upstream exploded');
            }

            public function providerName(): string
            {
                return 'broken';
            }

            public function modelName(): string
            {
                return 'broken-model';
            }
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()]);

        $response->assertStatus(503)
            ->assertJsonPath('retryable', true)
            // The image is still returned so the user can save it manually.
            ->assertJsonStructure(['message', 'data' => ['meal_image' => ['id', 'url']]]);

        $image = MealImage::firstOrFail();
        $this->assertSame(AnalysisStatus::Failed, $image->analysis_status);
        $this->assertNotNull($image->analysis_error);
    }

    public function test_a_malformed_provider_response_is_rejected_before_reaching_the_client(): void
    {
        $user = User::factory()->create();

        // Schema-violating payload: negative calories, confidence out of range.
        $this->swap(MealVisionAnalyzer::class, new class implements MealVisionAnalyzer
        {
            public function analyze(PreparedImage $image): array
            {
                return [
                    'meal_name' => 'Nonsense',
                    'confidence' => 4.2,
                    'notes' => '',
                    'items' => [
                        ['name' => 'Thing', 'portion_amount' => -1, 'portion_unit' => 'g', 'calories' => -50, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'confidence' => 9],
                    ],
                ];
            }

            public function providerName(): string
            {
                return 'liar';
            }

            public function modelName(): string
            {
                return 'liar-model';
            }
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()])
            ->assertStatus(502)
            ->assertJsonPath('retryable', true);
    }

    public function test_an_empty_analysis_reports_that_no_food_was_found(): void
    {
        $user = User::factory()->create();

        $this->swap(MealVisionAnalyzer::class, new class implements MealVisionAnalyzer
        {
            public function analyze(PreparedImage $image): array
            {
                return [
                    'meal_name' => 'No food detected',
                    'confidence' => 0,
                    'notes' => 'This looks like a photo of a keyboard.',
                    'items' => [],
                ];
            }

            public function providerName(): string
            {
                return 'fake';
            }

            public function modelName(): string
            {
                return 'fake-model';
            }
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()])
            ->assertStatus(422)
            ->assertJsonPath('retryable', false);
    }

    public function test_an_unconfigured_provider_reports_a_configuration_error(): void
    {
        config()->set('ai.provider', 'anthropic');
        config()->set('ai.providers.anthropic.api_key', '');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()])
            ->assertStatus(503)
            ->assertJsonPath('retryable', false);
    }

    public function test_an_unknown_provider_name_is_reported_rather_than_crashing(): void
    {
        config()->set('ai.provider', 'not-a-real-provider');

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/meals/analyze', ['image' => $this->photo()])
            ->assertStatus(503)
            ->assertJsonPath('retryable', false);
    }
}
