<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * The root of the OpenAPI document: metadata, servers, the API-key security
 * scheme, and the schemas shared across partner endpoints.
 *
 * Endpoint definitions live as attributes on the controllers that implement
 * them, so a route and its documentation cannot drift apart unnoticed.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'NutriLens Partner API',
    description: <<<'TEXT'
    **Snap your food. See your nutrition.**

    The NutriLens Partner API turns a meal — either a photograph of one or a
    structured list of foods — into calories and macronutrients.

    ## Getting started

    1. Create an API key in NutriLens under **Developer → API keys**.
    2. Copy it when it is shown. It is stored only as a hash, so it cannot be
       displayed again.
    3. Click **Authorize** above, paste the key, and try `GET /api/v1/ping` —
       it verifies the key without spending an AI call.

    ## Authentication

    Send the key as a bearer token on every request:

    ```
    Authorization: Bearer nl_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
    ```

    `X-API-Key: <key>` is also accepted for clients that reserve the
    Authorization header.

    ## Response shape

    Every response — success or failure — uses one of two envelopes:

    ```json
    { "success": true,  "data":  { ... } }
    { "success": false, "error": { "code": "INVALID_IMAGE", "message": "..." } }
    ```

    Branch on `error.code`, which is a closed and documented set. Never parse
    `error.message`; it is written for humans and may change.

    ## Rate limits

    Limits are applied per API key, not per IP.

    | Endpoint | Per minute | Per day |
    |---|---|---|
    | `POST /nutrition/analyze` | 10 | 250 |
    | `POST /nutrition/estimate` | 30 | 1000 |
    | `GET /ping` | 120 | — |

    A `429` response carries a `Retry-After` header.

    ## These are estimates

    NutriLens estimates nutrition; it does not measure it. Values are derived
    from a model's reading of a photograph or of a food description, and carry a
    calibrated `confidence` for exactly that reason. Nothing this API returns is
    medical or nutritional advice, and it must not be presented to end users as
    either.
    TEXT,
    contact: new OA\Contact(name: 'NutriLens API support', email: 'api@nutrilens.test'),
    license: new OA\License(name: 'Proprietary'),
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'This deployment')]
#[OA\Tag(name: 'Status', description: 'Key verification and API metadata')]
#[OA\Tag(name: 'Nutrition', description: 'Nutrition analysis and estimation')]
#[OA\SecurityScheme(
    securityScheme: 'ApiKeyAuth',
    type: 'http',
    scheme: 'bearer',
    description: 'Your NutriLens API key, e.g. `nl_live_a1b2c3...`. Paste the key itself — Swagger adds the `Bearer ` prefix.',
)]

/* ---------------------------------------------------------------------------
   Shared schemas
   --------------------------------------------------------------------------- */

#[OA\Schema(
    schema: 'NutritionItem',
    title: 'Nutrition item',
    description: 'One food, with nutrition for the portion given.',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Grilled chicken breast'),
        new OA\Property(property: 'portion_amount', type: 'number', format: 'float', example: 150),
        new OA\Property(property: 'portion_unit', type: 'string', enum: ['g', 'ml', 'oz', 'fl oz', 'cup', 'tbsp', 'tsp', 'slice', 'piece', 'serving', 'bowl', 'plate'], example: 'g'),
        new OA\Property(property: 'calories', description: 'kcal for this portion.', type: 'integer', example: 248),
        new OA\Property(property: 'protein', description: 'Grams for this portion.', type: 'number', format: 'float', example: 46),
        new OA\Property(property: 'carbs', description: 'Grams for this portion.', type: 'number', format: 'float', example: 0),
        new OA\Property(property: 'fat', description: 'Grams for this portion.', type: 'number', format: 'float', example: 5),
        new OA\Property(property: 'confidence', description: '0–1. Below 0.55 means the value should be reviewed before it is relied on.', type: 'number', format: 'float', example: 0.92),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'NutritionTotals',
    title: 'Nutrition totals',
    description: 'The sum of every item. Always consistent with the `items` array.',
    properties: [
        new OA\Property(property: 'calories', type: 'integer', example: 620),
        new OA\Property(property: 'protein', type: 'number', format: 'float', example: 48),
        new OA\Property(property: 'carbs', type: 'number', format: 'float', example: 72),
        new OA\Property(property: 'fat', type: 'number', format: 'float', example: 14),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'NutritionSuccess',
    title: 'Nutrition response',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'meal_name', type: 'string', example: 'Chicken Rice Bowl'),
                new OA\Property(property: 'confidence', description: 'Overall confidence, 0–1. Reflects the weakest meaningful part of the analysis rather than the average.', type: 'number', format: 'float', example: 0.88),
                new OA\Property(property: 'totals', ref: '#/components/schemas/NutritionTotals'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/NutritionItem')),
                new OA\Property(property: 'notes', description: 'One sentence on anything limiting the estimate, or null.', type: 'string', nullable: true, example: null),
                new OA\Property(
                    property: 'model',
                    properties: [
                        new OA\Property(property: 'provider', type: 'string', example: 'anthropic'),
                        new OA\Property(property: 'name', type: 'string', example: 'claude-opus-5'),
                    ],
                    type: 'object',
                ),
                new OA\Property(property: 'disclaimer', type: 'string', example: 'All values are estimates and are not medical or nutritional advice.'),
                new OA\Property(property: 'reference', description: 'Present only if you sent one.', type: 'string', nullable: true),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'EstimateRequest',
    title: 'Structured estimate request',
    required: ['items'],
    properties: [
        new OA\Property(property: 'meal_name', description: 'Optional. Kept verbatim in the response if supplied.', type: 'string', maxLength: 120, nullable: true, example: 'Post-gym lunch'),
        new OA\Property(
            property: 'items',
            description: 'One to 20 foods. Results come back in the same order.',
            type: 'array',
            items: new OA\Items(
                required: ['name', 'portion_amount', 'portion_unit'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 120, example: 'Chicken breast'),
                    new OA\Property(property: 'brand', description: 'Optional. Lowers confidence if unrecognised rather than being invented.', type: 'string', maxLength: 120, nullable: true),
                    new OA\Property(property: 'portion_amount', type: 'number', format: 'float', example: 150),
                    new OA\Property(property: 'portion_unit', type: 'string', enum: ['g', 'ml', 'oz', 'fl oz', 'cup', 'tbsp', 'tsp', 'slice', 'piece', 'serving', 'bowl', 'plate'], example: 'g'),
                ],
                type: 'object',
            ),
        ),
    ],
    type: 'object',
    example: [
        'meal_name' => 'Post-gym lunch',
        'items' => [
            ['name' => 'Chicken breast', 'portion_amount' => 150, 'portion_unit' => 'g'],
            ['name' => 'Brown rice', 'portion_amount' => 1, 'portion_unit' => 'cup'],
            ['name' => 'Broccoli', 'portion_amount' => 90, 'portion_unit' => 'g'],
        ],
    ],
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    title: 'Error',
    description: 'Every failure uses this shape. Branch on `error.code`.',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(
            property: 'error',
            properties: [
                new OA\Property(
                    property: 'code',
                    description: <<<'TEXT'
                    A closed set:

                    `MISSING_API_KEY`, `INVALID_API_KEY`, `REVOKED_API_KEY`,
                    `EXPIRED_API_KEY`, `FORBIDDEN`, `VALIDATION_FAILED`,
                    `INVALID_IMAGE`, `UNSUPPORTED_FILE_TYPE`, `FILE_TOO_LARGE`,
                    `NOT_FOUND`, `RATE_LIMITED`, `NO_FOOD_DETECTED`,
                    `AI_UNAVAILABLE`, `AI_NOT_CONFIGURED`, `AI_INVALID_RESPONSE`,
                    `INTERNAL_ERROR`.
                    TEXT,
                    type: 'string',
                    example: 'INVALID_IMAGE',
                ),
                new OA\Property(property: 'message', description: 'Human-readable. Do not parse.', type: 'string', example: 'The uploaded image could not be processed.'),
                new OA\Property(property: 'details', description: 'Field-level context, present on validation failures.', type: 'object', nullable: true, additionalProperties: true),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    title: 'Validation error',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(
            property: 'error',
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'VALIDATION_FAILED'),
                new OA\Property(property: 'message', type: 'string', example: 'The request could not be validated.'),
                new OA\Property(
                    property: 'details',
                    type: 'object',
                    example: [
                        'items.0.portion_unit' => ['`portion_unit` must be one of: g, ml, oz, fl oz, cup, tbsp, tsp, slice, piece, serving, bowl, plate.'],
                    ],
                    additionalProperties: true,
                ),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class ApiDocumentation
{
    // Attribute holder only. Nothing to implement.
}
