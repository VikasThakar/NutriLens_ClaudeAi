<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NutritionGoal\StoreNutritionGoalRequest;
use App\Http\Resources\NutritionGoalResource;
use App\Services\NutritionGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NutritionGoalController extends Controller
{
    public function __construct(private readonly NutritionGoalService $goals)
    {
    }

    /**
     * GET /api/nutrition-goals
     *
     * Returns the caller's active goal, or null if they have not set one.
     */
    public function show(Request $request): JsonResponse
    {
        $goal = $request->user()->activeNutritionGoal;

        return response()->json([
            'data' => $goal ? NutritionGoalResource::make($goal) : null,
        ]);
    }

    /**
     * PUT /api/nutrition-goals
     *
     * Replaces the active goal. Scoped to the authenticated user, so there is
     * no way to write another user's goals.
     */
    public function update(StoreNutritionGoalRequest $request): JsonResponse
    {
        $goal = $this->goals->setActiveGoal($request->user(), $request->validated());

        return response()->json([
            'message' => 'Nutrition goals updated.',
            'data' => NutritionGoalResource::make($goal),
        ]);
    }

    /**
     * GET /api/nutrition-goals/history
     *
     * Every goal the user has had, newest first. Retiring rather than
     * overwriting goals means this is a real record of what they were tracking
     * against, and when.
     */
    public function history(Request $request): JsonResponse
    {
        $goals = $request->user()->nutritionGoals()
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => NutritionGoalResource::collection($goals),
        ]);
    }
}
