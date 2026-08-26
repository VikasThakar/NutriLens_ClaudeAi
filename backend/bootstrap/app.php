<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Services\AI\Exceptions\AiException;
use App\Support\PartnerExceptionRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NutriLens authenticates the Next.js frontend with Sanctum *bearer
        // tokens* rather than stateful cookies, so no CSRF/session middleware
        // is layered onto the API group. CORS is handled globally by Laravel
        // using config/cors.php.
        $middleware->trustProxies(at: '*');

        // Partner API keys. Applied only to /api/v1/* routes — never to the
        // first-party endpoints, which stay on Sanctum.
        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
        ]);

        // Key resolution must happen *before* throttling, so the rate limiter
        // can bucket by API key rather than falling back to the caller's IP.
        // Without this, Laravel's own middleware priority puts ThrottleRequests
        // first and every partner behind one NAT shares a single budget.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateApiKey::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* failure should be JSON — never an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        /*
        | The public partner API answers in its own envelope, so it is handled
        | first. Registered before the first-party renderers below, whose
        | `api/*` guard would otherwise also match `api/v1/*`.
        */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (PartnerExceptionRenderer::handles($request)) {
                return PartnerExceptionRenderer::render($e, $request);
            }
        });

        /*
        | First-party API (the Next.js frontend).
        */

        // Catches AI failures raised outside the analysis controller's own
        // try/catch — most importantly a misconfigured AI_PROVIDER, which throws
        // while the container is still resolving the driver.
        $exceptions->render(function (AiException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->userMessage(),
                    'retryable' => $e->retryable(),
                ], $e->status());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated. Please sign in to continue.',
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'This action is unauthorized.',
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The requested resource could not be found.',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The requested endpoint could not be found.',
                ], 404);
            }
        });
    })->create();
