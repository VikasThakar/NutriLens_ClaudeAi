<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    /** Hard ceiling on a custom range, so one request cannot scan years. */
    private const MAX_RANGE_DAYS = 366;

    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    /**
     * GET /api/analytics?range=week|month|quarter|year
     * GET /api/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Every figure is computed from the caller's own logged meals. Days with no
     * meals are returned as days with no meals rather than dropped, so a chart
     * cannot imply continuity that was not there.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['sometimes', Rule::in(array_keys(AnalyticsService::RANGES))],
            'from' => ['sometimes', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $timezone = $user->tz();

        if (isset($validated['from'], $validated['to'])) {
            $from = Carbon::createFromFormat('Y-m-d', $validated['from'], $timezone)->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $validated['to'], $timezone)->startOfDay();

            if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
                return response()->json([
                    'message' => 'That date range is too long. Please request a year or less.',
                    'errors' => ['to' => ['The range cannot exceed 366 days.']],
                ], 422);
            }
        } else {
            $window = $this->analytics->resolveRange($user, $validated['range'] ?? 'week');
            $from = $window['from'];
            $to = $window['to'];
        }

        return response()->json([
            'data' => $this->analytics->report($user, $from, $to),
        ]);
    }
}
