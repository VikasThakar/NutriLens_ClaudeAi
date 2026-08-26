<?php

namespace App\Support;

use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\Exceptions\NoFoodDetectedException;
use App\Services\AI\UnsupportedImageException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Translates any exception raised under `/api/v1/*` into the partner API's error
 * envelope.
 *
 * Registered ahead of the first-party handlers in bootstrap/app.php, so a
 * partner never receives the frontend's `{ "message": ... }` shape — or worse, a
 * framework HTML page — regardless of where the failure came from.
 */
final class PartnerExceptionRenderer
{
    /** Whether this request belongs to the public partner API. */
    public static function handles(Request $request): bool
    {
        return $request->is('api/v1/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => PartnerApiResponse::error(
                PartnerApiResponse::VALIDATION_FAILED,
                'The request could not be validated.',
                422,
                $e->errors(),
            ),

            $e instanceof ThrottleRequestsException => PartnerApiResponse::error(
                PartnerApiResponse::RATE_LIMITED,
                'Rate limit exceeded. Slow down and retry after the number of seconds in the Retry-After header.',
                429,
                null,
                // Preserve the framework's Retry-After / X-RateLimit headers.
                // Their values arrive as integers, so they are cast rather than
                // filtered — filtering on is_string() silently drops all of them.
                array_map(
                    fn (mixed $value) => is_scalar($value) ? (string) $value : '',
                    $e->getHeaders(),
                ),
            ),

            $e instanceof UnsupportedImageException => PartnerApiResponse::error(
                PartnerApiResponse::INVALID_IMAGE,
                'The uploaded image could not be processed.',
                422,
            ),

            $e instanceof NoFoodDetectedException => PartnerApiResponse::error(
                PartnerApiResponse::NO_FOOD_DETECTED,
                'No identifiable food was found in that image.',
                422,
            ),

            $e instanceof AiConfigurationException => PartnerApiResponse::error(
                PartnerApiResponse::AI_NOT_CONFIGURED,
                'Nutrition analysis is not configured on this server.',
                503,
            ),

            $e instanceof AiUnavailableException => PartnerApiResponse::error(
                PartnerApiResponse::AI_UNAVAILABLE,
                'The nutrition analysis service is temporarily unavailable. Please retry.',
                503,
            ),

            $e instanceof AiResponseException => PartnerApiResponse::error(
                PartnerApiResponse::AI_INVALID_RESPONSE,
                'Nutrition analysis produced an unusable result. Please retry.',
                502,
            ),

            // Any AiException subclass not named above.
            $e instanceof AiException => PartnerApiResponse::error(
                PartnerApiResponse::AI_UNAVAILABLE,
                'Nutrition analysis failed. Please retry.',
                $e->status(),
            ),

            $e instanceof AuthenticationException => PartnerApiResponse::error(
                PartnerApiResponse::MISSING_API_KEY,
                'An API key is required. Send it as: Authorization: Bearer YOUR_API_KEY',
                401,
            ),

            $e instanceof AuthorizationException => PartnerApiResponse::error(
                PartnerApiResponse::FORBIDDEN,
                'This API key is not permitted to perform that action.',
                403,
            ),

            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => PartnerApiResponse::error(
                PartnerApiResponse::NOT_FOUND,
                'That endpoint or resource does not exist.',
                404,
            ),

            $e instanceof MethodNotAllowedHttpException => PartnerApiResponse::error(
                PartnerApiResponse::NOT_FOUND,
                'That HTTP method is not supported for this endpoint.',
                405,
            ),

            // A 413 from the web server or PHP's own upload limits arrives here
            // rather than as a validation failure.
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 413 => PartnerApiResponse::error(
                PartnerApiResponse::FILE_TOO_LARGE,
                'The uploaded file is too large.',
                413,
            ),

            default => self::internal($e, $request),
        };
    }

    /**
     * Anything unrecognised. The message is deliberately generic — an exception
     * message can carry a file path, a query or a credential, and none of that
     * belongs in a partner's response body. The detail goes to the log.
     */
    private static function internal(Throwable $e, Request $request): JsonResponse
    {
        Log::error('Unhandled partner API exception', [
            'path' => $request->path(),
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        if ($status < 500) {
            return PartnerApiResponse::error(
                PartnerApiResponse::VALIDATION_FAILED,
                'The request could not be processed.',
                $status,
            );
        }

        return PartnerApiResponse::error(
            PartnerApiResponse::INTERNAL_ERROR,
            'Something went wrong on our end. Please retry, and contact support if it persists.',
            500,
        );
    }
}
