<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meal\SmartPlateRequest;
use App\Models\Meal;
use App\Services\Nutrition\Data\PlateMeal;
use App\Services\Nutrition\SmartPlateService;
use Illuminate\Http\JsonResponse;

/**
 * NutriLens Smart Plate.
 *
 * One endpoint, and deliberately a stateless one: it takes the meal the review
 * screen is currently holding, reads the caller's own day out of MySQL, and
 * returns an analysis. Nothing is written, so the user can keep editing, and
 * abandoning the screen leaves nothing behind.
 *
 * The nutrition context comes from the authenticated user and nowhere else —
 * there is no parameter that can point it at another account.
 */
class SmartPlateController extends Controller
{
    public function __construct(private readonly SmartPlateService $smartPlate)
    {
    }

    /**
     * POST /api/meals/smart-plate
     */
    public function store(SmartPlateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $editing = $this->editingMeal($request, $validated['meal_id'] ?? null);

        $analysis = $this->smartPlate->analyse(
            $user,
            PlateMeal::fromRequest($validated['items']),
            $editing,
        );

        return response()->json(['data' => $analysis]);
    }

    /**
     * The saved meal being edited, if one was named.
     *
     * Resolved through the caller's own meals rather than by id alone: passing
     * somebody else's meal id must not reveal its macros, and it must not
     * silently distort this user's remaining totals either.
     */
    private function editingMeal(SmartPlateRequest $request, ?int $mealId): ?Meal
    {
        if ($mealId === null) {
            return null;
        }

        $meal = $request->user()->meals()->logged()->find($mealId);

        abort_if($meal === null, 404, 'The meal being edited could not be found.');

        return $meal;
    }
}
