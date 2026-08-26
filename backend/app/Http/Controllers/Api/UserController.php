<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/user
     *
     * The authenticated user plus their active nutrition goal — everything the
     * frontend needs to bootstrap a session.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('activeNutritionGoal');

        return response()->json([
            'data' => UserResource::make($user),
        ]);
    }

    /**
     * PATCH /api/user
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'email', 'timezone']))->save();

        return response()->json([
            'message' => 'Profile updated.',
            'data' => UserResource::make($user->load('activeNutritionGoal')),
        ]);
    }
}