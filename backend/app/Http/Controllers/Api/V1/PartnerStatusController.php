<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PortionUnit;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Support\PartnerApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Key verification and API metadata.
 *
 * Exists so a developer can confirm their key works — and that Swagger's
 * "Authorize" box is wired up — without spending an AI call to find out.
 */
class PartnerStatusController extends Controller
{
    #[OA\Get(
        path: '/api/v1/ping',
        operationId: 'partnerPing',
        summary: 'Verify an API key',
        description: <<<'TEXT'
        Confirms that your API key is valid and active, and reports the limits
        and accepted portion units for this deployment.

        Costs nothing and calls no AI provider — the right first request to make
        when setting up an integration.
        TEXT,
        security: [['ApiKeyAuth' => []]],
        tags: ['Status'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The key is valid',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'authenticated', type: 'boolean', example: true),
                                new OA\Property(property: 'api_version', type: 'string', example: 'v1'),
                                new OA\Property(
                                    property: 'key',
                                    properties: [
                                        new OA\Property(property: 'name', type: 'string', example: 'Acme staging'),
                                        new OA\Property(property: 'prefix', type: 'string', example: 'nl_live_a1b2c3'),
                                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                        new OA\Property(property: 'last_used_at', type: 'string', format: 'date-time', nullable: true),
                                    ],
                                    type: 'object',
                                ),
                                new OA\Property(
                                    property: 'limits',
                                    properties: [
                                        new OA\Property(property: 'analyze_per_minute', type: 'integer', example: 10),
                                        new OA\Property(property: 'analyze_per_day', type: 'integer', example: 250),
                                        new OA\Property(property: 'estimate_per_minute', type: 'integer', example: 30),
                                        new OA\Property(property: 'estimate_per_day', type: 'integer', example: 1000),
                                        new OA\Property(property: 'max_image_megabytes', type: 'integer', example: 12),
                                        new OA\Property(property: 'max_items_per_estimate', type: 'integer', example: 20),
                                    ],
                                    type: 'object',
                                ),
                                new OA\Property(
                                    property: 'portion_units',
                                    type: 'array',
                                    items: new OA\Items(type: 'string'),
                                    example: ['g', 'ml', 'oz', 'cup', 'slice', 'serving'],
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Missing, invalid or revoked API key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit exceeded', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function ping(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        return PartnerApiResponse::success([
            'authenticated' => true,
            'api_version' => 'v1',
            'key' => [
                'name' => $key->name,
                'prefix' => $key->key_prefix,
                'created_at' => $key->created_at?->toIso8601String(),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
            ],
            'limits' => [
                'analyze_per_minute' => 10,
                'analyze_per_day' => 250,
                'estimate_per_minute' => 30,
                'estimate_per_day' => 1000,
                'max_image_megabytes' => 12,
                'max_items_per_estimate' => (int) config('ai.estimation.max_items', 20),
            ],
            'portion_units' => PortionUnit::values(),
        ]);
    }
}
