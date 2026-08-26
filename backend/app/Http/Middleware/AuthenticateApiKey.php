<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use App\Support\PartnerApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a partner request from an `Authorization: Bearer <api key>`
 * header.
 *
 * The same header the first-party frontend uses for its Sanctum token, so a
 * partner integrating against NutriLens has one convention to learn. The two
 * never collide: this middleware only runs on `/api/v1/*`, and a NutriLens key
 * is recognisable by its `nl_live_` prefix.
 *
 * On success the resolved key and its owner are attached to the request:
 *
 *   $request->attributes->get('api_key')   // App\Models\ApiKey
 *   $request->attributes->get('api_user')  // App\Models\User
 *
 * The key's owner is *not* logged in as the authenticated user. Partner
 * endpoints operate on data supplied in the request, never on the owner's
 * meals, so there is nothing for a session to grant.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $keys)
    {
    }

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $presented = $this->extractKey($request);

        if ($presented === null) {
            return PartnerApiResponse::error(
                PartnerApiResponse::MISSING_API_KEY,
                'An API key is required. Send it as: Authorization: Bearer YOUR_API_KEY',
                401,
            );
        }

        $key = $this->keys->find($presented);

        if ($key === null) {
            // Logged without the key itself — a near-miss is worth seeing, the
            // secret is not worth writing to disk.
            Log::info('Partner API key rejected', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return PartnerApiResponse::error(
                PartnerApiResponse::INVALID_API_KEY,
                'That API key is not valid.',
                401,
            );
        }

        if ($key->isRevoked()) {
            return PartnerApiResponse::error(
                PartnerApiResponse::REVOKED_API_KEY,
                'That API key has been revoked. Create a new one in your NutriLens account.',
                401,
            );
        }

        if ($key->isExpired()) {
            return PartnerApiResponse::error(
                PartnerApiResponse::EXPIRED_API_KEY,
                'That API key has expired. Create a new one in your NutriLens account.',
                401,
            );
        }

        if ($ability !== null && ! $key->can($ability)) {
            return PartnerApiResponse::error(
                PartnerApiResponse::FORBIDDEN,
                "This API key is not permitted to use {$ability}.",
                403,
            );
        }

        // A key whose owner has been deleted cannot be used, even though the
        // cascade should have removed it.
        if ($key->user === null) {
            return PartnerApiResponse::error(
                PartnerApiResponse::INVALID_API_KEY,
                'That API key is not valid.',
                401,
            );
        }

        $this->keys->touch($key);

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_user', $key->user);

        return $next($request);
    }

    /**
     * Accepts `Authorization: Bearer <key>`, and `X-API-Key: <key>` as a
     * convenience for tools that reserve the Authorization header.
     */
    private function extractKey(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) === 1) {
            return trim($matches[1]);
        }

        $alternate = $request->header('X-API-Key');

        return is_string($alternate) && trim($alternate) !== '' ? trim($alternate) : null;
    }
}
