<?php

namespace App\Http\Controllers\Api;

use App\Enums\GoalSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CompleteOnboardingRequest;
use App\Http\Resources\UserResource;
use App\Services\NutritionGoalService;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function __construct(private readonly NutritionGoalService $goals)
    {
    }

    /**
     * POST /api/onboarding
     *
     * Stores the chosen goal and daily targets, then marks the account as
     * onboarded. Omitted targets fall back to the defaults for the goal, which
     * is what happens when the user skips the final step.
     */
    public function store(CompleteOnboardingRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->goals->setActiveGoal($user, $request->validated(), GoalSource::Onboarding);

        $user->forceFill(['onboarded_at' => now()])->save();

        return response()->json([
            'message' => 'You are all set.',
            'data' => UserResource::make($user->load('activeNutritionGoal')),
        ]);
    }
}