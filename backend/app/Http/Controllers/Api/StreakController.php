<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreakController extends Controller
{
    public function __construct(private readonly StreakService $streaks)
    {
    }

    /**
     * GET /api/streak
     *
     * The caller's current and longest logging streaks, plus the last fortnight
     * of activity. See StreakService for the rules — a day counts once, however
     * many meals are on it, and dates are the user's own calendar dates.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->streaks->forUser($request->user()),
        ]);
    }
}
