<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-party API key management, for the Developer screen in the NutriLens app.
 *
 * Authenticated with Sanctum, like every other frontend endpoint — a partner API
 * key cannot be used to mint more partner API keys.
 */
class ApiKeyController extends Controller
{
    /** A soft ceiling, so one account cannot fill the table. */
    private const MAX_ACTIVE_KEYS = 10;

    public function __construct(private readonly ApiKeyService $keys)
    {
    }

    /**
     * GET /api/api-keys
     *
     * The caller's keys, newest first. Revoked keys are kept and returned: a
     * disappearing key is worse than a visibly revoked one when you are trying
     * to work out what an integration is using.
     */
    public function index(Request $request): JsonResponse
    {
        $keys = $request->user()->apiKeys()->newestFirst()->get();

        return response()->json([
            'data' => ApiKeyResource::collection($keys),
            'meta' => [
                'active_count' => $keys->filter(fn (ApiKey $key) => $key->isActive())->count(),
                'max_active' => self::MAX_ACTIVE_KEYS,
            ],
        ]);
    }

    /**
     * POST /api/api-keys
     *
     * Creates a key and returns the plaintext — the only time it will ever be
     * available. Only a SHA-256 digest reaches the database.
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $user = $request->user();

        $activeCount = $user->apiKeys()->active()->count();

        if ($activeCount >= self::MAX_ACTIVE_KEYS) {
            return response()->json([
                'message' => sprintf(
                    'You already have %d active API keys, which is the maximum. Revoke one before creating another.',
                    self::MAX_ACTIVE_KEYS,
                ),
                'errors' => ['name' => ['Revoke an existing key first.']],
            ], 422);
        }

        $created = $this->keys->create($user, $request->validated('name'));

        return response()->json([
            'message' => 'API key created. Copy it now — it cannot be shown again.',
            'data' => [
                'key' => ApiKeyResource::make($created['key']),
                // Returned exactly once, from this response only.
                'plain_text_key' => $created['plain_text'],
            ],
        ], 201);
    }

    /**
     * DELETE /api/api-keys/{apiKey}
     *
     * Revokes rather than deletes, so the audit trail survives. Ownership is
     * checked explicitly — the route binds by id, so scoping cannot be left to
     * the query alone.
     */
    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 403);

        $this->keys->revoke($apiKey);

        return response()->json([
            'message' => 'API key revoked. Any integration using it will now be rejected.',
            'data' => ApiKeyResource::make($apiKey->fresh()),
        ]);
    }
}
