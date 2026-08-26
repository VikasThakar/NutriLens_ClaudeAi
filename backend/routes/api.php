<?php

use App\Http\Controllers\Api\AiCoachController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GoalCalculatorController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\MealAnalysisController;
use App\Http\Controllers\Api\MealController;
use App\Http\Controllers\Api\MealImageController;
use App\Http\Controllers\Api\NutritionGoalController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\StreakController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\PartnerNutritionController;
use App\Http\Controllers\Api\V1\PartnerStatusController;
use App\Http\Controllers\Api\WeeklyInsightController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| NutriLens API
|--------------------------------------------------------------------------
|
| Two audiences, two authentication schemes, two response envelopes:
|
|   /api/*      the first-party Next.js frontend, on Sanctum personal access
|               tokens, answering { data | message | errors }.
|
|   /api/v1/*   the public partner API, on hashed API keys, answering
|               { success, data } / { success, error }. Versioned, because
|               partners cannot be redeployed alongside us.
|
| Both take the credential as `Authorization: Bearer <token>`.
|
*/

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'nutrilens-api',
    'time' => now()->toIso8601String(),
]));

/*
|--------------------------------------------------------------------------
| Public partner API — v1
|--------------------------------------------------------------------------
|
| Every route is gated by an API key and throttled per key. `api.key` runs
| before `throttle`, so the limiter buckets by key rather than by IP.
|
| These endpoints never read or write the key owner's meals: a partner request
| is a pure function of its own input.
|
*/
Route::prefix('v1')->group(function () {
    Route::middleware(['api.key', 'throttle:partner-api'])
        ->get('/ping', [PartnerStatusController::class, 'ping']);

    Route::prefix('nutrition')->group(function () {
        // Vision. The most expensive call NutriLens makes, so the tightest limit.
        Route::middleware(['api.key:nutrition:analyze', 'throttle:partner-analyze'])
            ->post('/analyze', [PartnerNutritionController::class, 'analyze']);

        // Text. Cheaper, so a more generous limit.
        Route::middleware(['api.key:nutrition:estimate', 'throttle:partner-estimate'])
            ->post('/estimate', [PartnerNutritionController::class, 'estimate']);
    });
});

/*
|--------------------------------------------------------------------------
| First-party API
|--------------------------------------------------------------------------
*/

/*
| Public — throttled to blunt credential stuffing.
*/
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

/*
| Meal photos.
|
| Deliberately outside auth:sanctum: an <img> tag cannot send a bearer token.
| Access is granted instead by a short-lived signature that only the owner's
| API responses ever contain.
*/
Route::get('/meal-images/{mealImage}/file', [MealImageController::class, 'show'])
    ->middleware('signed')
    ->name('meal-images.show');

/*
| Authenticated
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [UserController::class, 'show']);
    Route::patch('/user', [UserController::class, 'update']);

    /*
    | Goals. The calculator routes come before the collection routes because
    | they are literal segments, and they only ever return an estimate — saving
    | is still a separate, explicit PUT.
    */
    Route::get('/nutrition-goals/history', [NutritionGoalController::class, 'history']);
    Route::get('/nutrition-goals/calculator', [GoalCalculatorController::class, 'show']);
    Route::post('/nutrition-goals/calculate', [GoalCalculatorController::class, 'store']);
    Route::get('/nutrition-goals', [NutritionGoalController::class, 'show']);
    Route::put('/nutrition-goals', [NutritionGoalController::class, 'update']);

    Route::post('/onboarding', [OnboardingController::class, 'store']);

    Route::get('/dashboard/today', [DashboardController::class, 'today']);

    /*
    | History, analytics and streaks. All read-only aggregations over the
    | caller's own meals.
    */
    Route::get('/history/day', [HistoryController::class, 'day']);
    Route::get('/history/calendar', [HistoryController::class, 'calendar']);
    Route::get('/analytics', [AnalyticsController::class, 'index']);
    Route::get('/streak', [StreakController::class, 'show']);

    /*
    | Weekly AI insights. Reading is free; generating costs money upstream, so
    | it is throttled separately and reuses a stored summary whenever the
    | underlying numbers have not changed.
    */
    Route::get('/insights', [WeeklyInsightController::class, 'index']);
    Route::get('/insights/current', [WeeklyInsightController::class, 'current']);
    Route::post('/insights/generate', [WeeklyInsightController::class, 'generate'])
        ->middleware('throttle:10,1');
    Route::get('/insights/{insight}', [WeeklyInsightController::class, 'show']);

    /*
    | Partner API key management. Sanctum-only: a partner key can never mint
    | another partner key.
    */
    Route::get('/api-keys', [ApiKeyController::class, 'index']);
    Route::post('/api-keys', [ApiKeyController::class, 'store'])
        ->middleware('throttle:api-keys');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);

    /*
    | AI meal analysis. Throttled separately and more tightly than the rest of
    | the API: every call costs money upstream.
    */
    Route::post('/meals/analyze', [MealAnalysisController::class, 'store'])
        ->middleware('throttle:20,1');

    // The NutriLens Tip for one meal. No AI call — see MealTipService.
    Route::get('/meals/{meal}/tip', [MealController::class, 'tip']);

    Route::apiResource('meals', MealController::class);

    /*
    | AI Coach.
    |
    | Reading and managing threads is free, so those routes carry only the
    | shared per-user limiter. Sending a message is the one action that calls a
    | provider, so it is throttled separately and far more tightly — see the
    | `ai-coach` limiter in RateLimitServiceProvider.
    */
    Route::prefix('ai-coach')->group(function () {
        Route::get('/context', [AiCoachController::class, 'context']);

        Route::get('/conversations', [AiCoachController::class, 'index']);
        Route::post('/conversations', [AiCoachController::class, 'store'])
            ->middleware('throttle:ai-coach-threads');
        Route::get('/conversations/{conversation}', [AiCoachController::class, 'show']);
        Route::delete('/conversations/{conversation}', [AiCoachController::class, 'destroy']);

        Route::post('/conversations/{conversation}/messages', [AiCoachController::class, 'sendMessage'])
            ->middleware('throttle:ai-coach');
    });
});
