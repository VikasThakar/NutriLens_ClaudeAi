<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AnalyzeNutritionImageRequest;
use App\Http\Requests\V1\EstimateNutritionRequest;
use App\Models\ApiKey;
use App\Services\AI\Data\AnalyzedMeal;
use App\Services\AI\Data\FoodQuery;
use App\Services\AI\FoodEstimationService;
use App\Services\AI\MealAnalysisService;
use App\Support\PartnerApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * The public partner API.
 *
 * Two ways in, one response shape out:
 *
 *   POST /api/v1/nutrition/analyze   — a photograph
 *   POST /api/v1/nutrition/estimate  — structured foods and portions
 *
 * Neither endpoint touches the key owner's own meals. Nothing is written to
 * `meals`, `meal_items` or `meal_images`: a partner request is a pure function
 * of its input, so there is no user data to leak and nothing to clean up.
 */
class PartnerNutritionController extends Controller
{
    public function __construct(
        private readonly MealAnalysisService $vision,
        private readonly FoodEstimationService $estimation,
    ) {
    }

    #[OA\Post(
        path: '/api/v1/nutrition/analyze',
        operationId: 'analyzeNutritionImage',
        summary: 'Analyse a meal photograph',
        description: <<<'TEXT'
        Upload a photograph of a meal and receive a nutrition estimate for every
        food the model can identify: portion, calories, protein, carbohydrates,
        fat, and a calibrated confidence per item.

        The image is re-oriented, downscaled and re-encoded before it is sent to
        the vision provider, and is **not stored** — partner uploads are held in
        memory for the duration of the request only.

        Every figure returned is an estimate.
        TEXT,
        security: [['ApiKeyAuth' => []]],
        tags: ['Nutrition'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['image'],
                    properties: [
                        new OA\Property(
                            property: 'image',
                            description: 'JPEG, PNG or WebP. Max 12 MB, at least 64x64 pixels.',
                            type: 'string',
                            format: 'binary',
                        ),
                        new OA\Property(
                            property: 'reference',
                            description: 'Optional identifier of your own, echoed back in the response.',
                            type: 'string',
                            maxLength: 120,
                            nullable: true,
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nutrition analysis',
                content: new OA\JsonContent(ref: '#/components/schemas/NutritionSuccess'),
            ),
            new OA\Response(response: 401, description: 'Missing, invalid or revoked API key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 413, description: 'Upload larger than the server accepts', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid image, unsupported format, or no food detected', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit exceeded', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 502, description: 'The AI returned an unusable result', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 503, description: 'AI unavailable or not configured', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function analyze(AnalyzeNutritionImageRequest $request): JsonResponse
    {
        $key = $this->key($request);

        // AiExceptions propagate to PartnerExceptionRenderer, which maps each
        // one to its documented error code.
        $analysis = $this->vision->analyzeUpload($request->file('image'));

        Log::info('Partner image analysis', [
            'api_key_id' => $key->id,
            'items' => count($analysis->items),
            'provider' => $analysis->provider,
        ]);

        return PartnerApiResponse::success(
            $this->payload($analysis, $request->validated('reference'))
        );
    }

    #[OA\Post(
        path: '/api/v1/nutrition/estimate',
        operationId: 'estimateNutrition',
        summary: 'Estimate nutrition for structured foods',
        description: <<<'TEXT'
        Send the foods and portions you already know and receive nutrition for
        exactly those foods, in the same order, in the same response shape as the
        image endpoint.

        Portions are echoed back unchanged: the estimate is for the quantity you
        sent, and unit conversion is deliberately not performed on your behalf.

        Every figure returned is an estimate.
        TEXT,
        security: [['ApiKeyAuth' => []]],
        tags: ['Nutrition'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/EstimateRequest'),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Nutrition estimate',
                content: new OA\JsonContent(ref: '#/components/schemas/NutritionSuccess'),
            ),
            new OA\Response(response: 401, description: 'Missing, invalid or revoked API key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit exceeded', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 502, description: 'The AI returned an unusable result', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 503, description: 'AI unavailable or not configured', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function estimate(EstimateNutritionRequest $request): JsonResponse
    {
        $key = $this->key($request);

        $estimate = $this->estimation->estimate(new FoodQuery(
            items: $request->foods(),
            mealName: $request->mealName(),
        ));

        Log::info('Partner structured estimate', [
            'api_key_id' => $key->id,
            'items' => count($estimate->items),
            'provider' => $estimate->provider,
        ]);

        return PartnerApiResponse::success($this->payload($estimate));
    }

    /**
     * One payload builder for both endpoints, so an integration can switch
     * between them without touching its parser.
     *
     * @return array<string, mixed>
     */
    private function payload(AnalyzedMeal $meal, ?string $reference = null): array
    {
        $data = [
            'meal_name' => $meal->mealName,
            'confidence' => $meal->confidence,
            'totals' => [
                'calories' => $meal->totalCalories(),
                'protein' => $meal->totalProtein(),
                'carbs' => $meal->totalCarbs(),
                'fat' => $meal->totalFat(),
            ],
            'items' => array_map(fn ($item) => $item->toArray(), $meal->items),
            'notes' => $meal->notes,
            'model' => [
                'provider' => $meal->provider,
                'name' => $meal->model,
            ],
            // Stated in the payload, not just the docs. A downstream system
            // should be able to see that these are estimates without reading
            // prose.
            'disclaimer' => 'All values are estimates and are not medical or nutritional advice.',
        ];

        if ($reference !== null && trim($reference) !== '') {
            $data['reference'] = trim($reference);
        }

        return $data;
    }

    /** The key resolved by the api.key middleware. */
    private function key(Request $request): ApiKey
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        return $key;
    }
}
