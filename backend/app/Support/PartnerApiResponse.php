<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The single response envelope for the public partner API.
 *
 * Every v1 endpoint — and every v1 failure, including ones raised by framework
 * middleware — answers in one of exactly two shapes:
 *
 *   { "success": true,  "data":  { ... } }
 *   { "success": false, "error": { "code": "...", "message": "...", "details"?: {...} } }
 *
 * The codes are a closed, documented set. A partner can branch on
 * `error.code` and never has to parse a message string.
 */
final class PartnerApiResponse
{
    /* Authentication and authorisation */
    public const MISSING_API_KEY = 'MISSING_API_KEY';

    public const INVALID_API_KEY = 'INVALID_API_KEY';

    public const REVOKED_API_KEY = 'REVOKED_API_KEY';

    public const EXPIRED_API_KEY = 'EXPIRED_API_KEY';

    public const FORBIDDEN = 'FORBIDDEN';

    /* Request problems */
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const INVALID_IMAGE = 'INVALID_IMAGE';

    public const UNSUPPORTED_FILE_TYPE = 'UNSUPPORTED_FILE_TYPE';

    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';

    public const NOT_FOUND = 'NOT_FOUND';

    public const RATE_LIMITED = 'RATE_LIMITED';

    /* Downstream and server problems */
    public const NO_FOOD_DETECTED = 'NO_FOOD_DETECTED';

    public const AI_UNAVAILABLE = 'AI_UNAVAILABLE';

    public const AI_NOT_CONFIGURED = 'AI_NOT_CONFIGURED';

    public const AI_INVALID_RESPONSE = 'AI_INVALID_RESPONSE';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    /**
     * @param  array<string, mixed>|null  $details  Field-level context, e.g. validation errors.
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
        array $headers = [],
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null && $details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['success' => false, 'error' => $error], $status, $headers);
    }
}
