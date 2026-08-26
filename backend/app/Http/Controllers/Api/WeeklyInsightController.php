<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Insight\GenerateWeeklyInsightRequest;
use App\Http\Resources\WeeklyInsightResource;
use App\Models\WeeklyInsight;
use App\Services\AI\Exceptions\AiConfigurationException;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\WeeklyInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Weekly AI insights.
 *
 * Every action is scoped through `$request->user()->weeklyInsights()`, so there
 * is no parameter that can widen a response to another account.
 */
class WeeklyInsightController extends Controller
{
    public function __construct(private readonly WeeklyInsightService $insights)
    {
    }

    /**
     * GET /api/insights
     *
     * Previously generated summaries, newest week first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:52'],
        ]);

        $paginated = $request->user()->weeklyInsights()
            ->newestFirst()
            ->paginate($validated['per_page'] ?? 12);

        return response()->json([
            'data' => WeeklyInsightResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * GET /api/insights/current?date=YYYY-MM-DD
     *
     * The state of one week: its real aggregates, the stored summary if there
     * is one, and whether that summary still describes the current data. No AI
     * call happens here — this is what the Insights screen loads on arrival.
     */
    public function current(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $date = $validated['date'] ?? null;

        $window = $this->insights->weekWindow($user, $date);
        $preview = $this->insights->preview($user, $date);

        $stored = $user->weeklyInsights()
            ->whereDate('week_start', $window['start']->toDateString())
            ->first();

        $enoughData = $preview['aggregates']['days_logged'] >= WeeklyInsightService::MIN_DAYS_FOR_INSIGHT;

        return response()->json([
            'data' => [
                'week_start' => $window['start']->toDateString(),
                'week_end' => $window['end']->toDateString(),
                'is_current_week' => $window['start']->isSameDay(
                    $user->today()->startOfWeek(Carbon::MONDAY)
                ),
                'aggregates' => $preview['aggregates'],
                'insight' => $stored ? WeeklyInsightResource::make($stored) : null,
                // True when the meals behind a stored summary have changed
                // since it was written, which is when regenerating is worth
                // offering and not before.
                'is_stale' => $stored ? $this->insights->isStale($user, $stored) : false,
                'has_enough_data' => $enoughData,
                'requirement' => [
                    'min_days_logged' => WeeklyInsightService::MIN_DAYS_FOR_INSIGHT,
                    'days_logged' => $preview['aggregates']['days_logged'],
                ],
            ],
        ]);
    }

    /**
     * GET /api/insights/{insight}
     */
    public function show(Request $request, WeeklyInsight $insight): JsonResponse
    {
        // Ownership, not just authentication.
        abort_unless($insight->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => WeeklyInsightResource::make($insight),
        ]);
    }

    /**
     * POST /api/insights/generate
     *
     * Aggregates the week, reuses a stored summary when the numbers behind it
     * have not changed, and otherwise calls the AI once and stores the result.
     */
    public function generate(GenerateWeeklyInsightRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        try {
            $result = $this->insights->generateFor(
                $user,
                $validated['date'] ?? null,
                (bool) ($validated['force'] ?? false),
            );
        } catch (AiException $e) {
            Log::warning('Weekly insight generation failed', [
                'user_id' => $user->id,
                'provider' => config('ai.provider'),
                'exception' => $e::class,
            ]);

            return response()->json([
                'message' => $this->messageFor($e),
                'retryable' => $e->retryable(),
            ], $e->status());
        }

        // Not an error: the week genuinely does not have enough logged days for
        // a summary to say anything true. The client renders this as guidance,
        // not as a failure.
        if ($result['status'] === 'insufficient_data') {
            return response()->json([
                'status' => 'insufficient_data',
                'message' => sprintf(
                    'Log at least %d days in a week before generating a summary — %s so far.',
                    $result['requirement']['min_days_logged'],
                    $result['requirement']['days_logged'] === 1
                        ? 'there is 1 day'
                        : "there are {$result['requirement']['days_logged']} days",
                ),
                'data' => [
                    'aggregates' => $result['aggregates'],
                    'requirement' => $result['requirement'],
                ],
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'message' => $result['reused']
                ? 'Your summary for this week is already up to date.'
                : 'Weekly summary generated.',
            'reused' => $result['reused'],
            'data' => [
                'insight' => WeeklyInsightResource::make($result['insight']),
                'aggregates' => $result['aggregates'],
            ],
        ]);
    }

    /**
     * The shared AiException messages are written for photo analysis, so they
     * are re-phrased here for the insight context rather than shown verbatim.
     */
    private function messageFor(AiException $exception): string
    {
        return match (true) {
            $exception instanceof AiConfigurationException =>
                'Weekly summaries are not configured on this server yet. Your nutrition figures below are unaffected.',
            $exception instanceof AiUnavailableException =>
                'The AI service is temporarily unavailable. Please try again in a moment.',
            $exception instanceof AiResponseException =>
                'The summary that came back did not hold up against your data, so it was discarded. Please try again.',
            default => 'Your weekly summary could not be generated. Please try again.',
        };
    }
}
