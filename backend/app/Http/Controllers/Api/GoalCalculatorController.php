<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityLevel;
use App\Enums\BiologicalSex;
use App\Enums\GoalType;
use App\Http\Controllers\Controller;
use App\Http\Requests\NutritionGoal\CalculateTargetsRequest;
use App\Services\Goals\GoalCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The optional nutrition goal calculator.
 *
 * It calculates and returns — it never saves a goal. The user reviews the
 * estimate, adjusts anything they like, and only then sends it to
 * PUT /api/nutrition-goals. Keeping those two steps apart is what makes
 * "these are estimates you can change" true rather than just claimed.
 */
class GoalCalculatorController extends Controller
{
    public function __construct(private readonly GoalCalculatorService $calculator)
    {
    }

    /**
     * GET /api/nutrition-goals/calculator
     *
     * The options the calculator offers, plus whatever the user entered last
     * time so the form arrives pre-filled instead of blank.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'activity_levels' => array_map(fn (ActivityLevel $level) => [
                    'value' => $level->value,
                    'label' => $level->label(),
                    'description' => $level->description(),
                    'multiplier' => $level->multiplier(),
                ], ActivityLevel::cases()),

                'biological_sexes' => array_map(fn (BiologicalSex $sex) => [
                    'value' => $sex->value,
                    'label' => $sex->label(),
                ], BiologicalSex::cases()),

                'goal_types' => array_map(fn (GoalType $goal) => [
                    'value' => $goal->value,
                    'label' => $goal->label(),
                ], GoalType::cases()),

                'formula' => 'Mifflin-St Jeor',

                // Null on every field for a user who has never used it.
                'saved_inputs' => [
                    'age' => $user->age,
                    'height_cm' => $user->height_cm,
                    'weight_kg' => $user->weight_kg,
                    'activity_level' => $user->activity_level?->value,
                    'biological_sex' => $user->biological_sex?->value,
                ],
            ],
        ]);
    }

    /**
     * POST /api/nutrition-goals/calculate
     *
     * Returns an estimate. Nothing about the user's active goal changes.
     */
    public function store(CalculateTargetsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $sex = BiologicalSex::tryFrom((string) ($data['biological_sex'] ?? ''))
            ?? BiologicalSex::Unspecified;

        $estimate = $this->calculator->estimate(
            age: (int) $data['age'],
            heightCm: (int) $data['height_cm'],
            weightKg: (float) $data['weight_kg'],
            activityLevel: ActivityLevel::from($data['activity_level']),
            goalType: GoalType::from($data['goal_type']),
            biologicalSex: $sex,
        );

        // Remembered on the profile purely so the calculator can be re-opened
        // without retyping. Nothing else in the app reads these.
        $user->fill([
            'age' => (int) $data['age'],
            'height_cm' => (int) $data['height_cm'],
            'weight_kg' => (float) $data['weight_kg'],
            'activity_level' => $data['activity_level'],
            'biological_sex' => $sex->value,
        ])->save();

        return response()->json([
            'message' => 'Estimate calculated.',
            'data' => $estimate->toArray(),
        ]);
    }
}
