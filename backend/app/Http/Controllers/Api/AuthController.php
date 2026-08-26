<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/register
     *
     * Creates the account and immediately issues an API token so the client
     * can move straight into onboarding.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // The 'hashed' cast on User::$password handles the bcrypt hashing.
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        // refresh() pulls in database-level defaults (e.g. timezone) that the
        // freshly-instantiated model does not yet know about.
        $user->refresh()->load('activeNutritionGoal');

        return response()->json([
            'message' => 'Welcome to NutriLens.',
            'data' => [
                'user' => UserResource::make($user),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * POST /api/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email')->toString())->first();

        // A single generic message for both branches so the endpoint cannot be
        // used to enumerate which emails have accounts.
        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        $user->load('activeNutritionGoal');

        return response()->json([
            'message' => 'Signed in successfully.',
            'data' => [
                'user' => UserResource::make($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * POST /api/logout
     *
     * Revokes only the token used for this request, leaving the user's other
     * devices signed in.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Signed out successfully.',
        ]);
    }

    private function deviceName(Request $request): string
    {
        $name = $request->input('device_name');

        if (is_string($name) && trim($name) !== '') {
            return substr(trim($name), 0, 120);
        }

        return substr((string) $request->userAgent(), 0, 120) ?: 'nutrilens-web';
    }
}