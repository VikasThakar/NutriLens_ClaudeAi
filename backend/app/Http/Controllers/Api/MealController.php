<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meal\StoreMealRequest;
use App\Http\Requests\Meal\UpdateMealRequest;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use App\Services\AI\MealTipService;
use App\Services\MealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealController extends Controller
{
    public function __construct(
        private readonly MealService $meals,
        private readonly MealTipService $tips,
    ) {
    }

    /**
     * GET /api/meals
     *
     * Paginated, newest first. Always scoped to the authenticated user — there
     * is no query parameter that can widen it to another account.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'meal_type' => ['sometimes', Rule::enum(MealType::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()->meals()
            ->logged()
            ->with(['items', 'image'])
            ->orderByDesc('consumed_at')
            ->orderByDesc('id');

        if (isset($validated['date'])) {
            $query->whereDate('consumed_on', $validated['date']);
        }

        if (isset($validated['from'])) {
            $query->whereDate('consumed_on', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->whereDate('consumed_on', '<=', $validated['to']);
        }

        if (isset($validated['meal_type'])) {
            $query->where('meal_type', $validated['meal_type']);
        }

        $meals = $query->paginate($validated['per_page'] ?? 20)->withQueryString();

        return response()->json([
            'data' => MealResource::collection($meals->items()),
            'meta' => [
                'current_page' => $meals->currentPage(),
                'last_page' => $meals->lastPage(),
                'per_page' => $meals->perPage(),
                'total' => $meals->total(),
            ],
        ]);
    }

    /**
     * POST /api/meals
     *
     * Saves a reviewed AI analysis or a manually entered meal — both take the
     * same shape and the same path.
     */
    public function store(StoreMealRequest $request): JsonResponse
    {
        $meal = $this->meals->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'Meal saved.',
            'data' => MealResource::make($meal),
            /*
             * A one-line read on how this meal sits against the rest of the
             * day. Computed, not generated: no AI call, no extra latency, no
             * cost — see MealTipService.
             */
            'tip' => $this->tips->forMeal($request->user(), $meal),
        ], 201);
    }

    /**
     * GET /api/meals/{meal}/tip
     *
     * The same NutriLens Tip, for the meal detail sheet. Separate from `show`
     * so the meal list is not made to pay for a tip nobody is looking at.
     */
    public function tip(Request $request, Meal $meal): JsonResponse
    {
        $this->authorize('view', $meal);

        return response()->json([
            'data' => $this->tips->forMeal($request->user(), $meal),
        ]);
    }

    /**
     * GET /api/meals/{meal}
     */
    public function show(Request $request, Meal $meal): JsonResponse
    {
        $this->authorize('view', $meal);

        return response()->json([
            'data' => MealResource::make($meal->load(['items', 'image'])),
        ]);
    }

    /**
     * PUT /api/meals/{meal}
     */
    public function update(UpdateMealRequest $request, Meal $meal): JsonResponse
    {
        $this->authorize('update', $meal);

        $meal = $this->meals->update($meal, $request->validated());

        return response()->json([
            'message' => 'Meal updated.',
            'data' => MealResource::make($meal),
        ]);
    }

    /**
     * DELETE /api/meals/{meal}
     */
    public function destroy(Request $request, Meal $meal): JsonResponse
    {
        $this->authorize('delete', $meal);

        $this->meals->delete($meal);

        return response()->json([
            'message' => 'Meal deleted.',
        ]);
    }
}
