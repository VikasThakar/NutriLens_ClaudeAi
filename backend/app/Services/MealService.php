<?php

namespace App\Services;

use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Enums\MealType;
use App\Models\Meal;
use App\Models\MealImage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates meals with their items in one transaction, keeping the
 * denormalised totals on `meals` in step with the rows in `meal_items`.
 */
class MealService
{
    /**
     * @param  array<string, mixed>  $data  Validated payload from StoreMealRequest
     */
    public function create(User $user, array $data): Meal
    {
        return DB::transaction(function () use ($user, $data) {
            $consumedAt = $this->resolveConsumedAt($user, $data['consumed_at'] ?? null);

            $meal = $user->meals()->create([
                'meal_name' => $data['meal_name'],
                'meal_type' => MealType::from($data['meal_type']),
                'source' => MealSource::from($data['source'] ?? MealSource::Manual->value),
                'status' => MealStatus::Logged,
                'ai_confidence' => $data['ai_confidence'] ?? null,
                'ai_provider' => $data['ai_provider'] ?? null,
                'ai_model' => $data['ai_model'] ?? null,
                'consumed_at' => $consumedAt,
                'consumed_on' => $consumedAt->copy()->setTimezone($this->timezone($user))->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceItems($meal, $data['items']);

            // The image was uploaded during analysis and is not yet attached.
            if (! empty($data['meal_image_id'])) {
                $this->attachImage($user, $meal, (int) $data['meal_image_id']);
            }

            $meal->recalculateTotals();

            return $meal->load(['items', 'image']);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload from UpdateMealRequest
     */
    public function update(Meal $meal, array $data): Meal
    {
        return DB::transaction(function () use ($meal, $data) {
            $user = $meal->user;

            $attributes = [];

            if (array_key_exists('meal_name', $data)) {
                $attributes['meal_name'] = $data['meal_name'];
            }

            if (array_key_exists('meal_type', $data)) {
                $attributes['meal_type'] = MealType::from($data['meal_type']);
            }

            if (array_key_exists('notes', $data)) {
                $attributes['notes'] = $data['notes'];
            }

            if (array_key_exists('consumed_at', $data) && $data['consumed_at'] !== null) {
                $consumedAt = $this->resolveConsumedAt($user, $data['consumed_at']);
                $attributes['consumed_at'] = $consumedAt;
                $attributes['consumed_on'] = $consumedAt->copy()
                    ->setTimezone($this->timezone($user))
                    ->toDateString();
            }

            if ($attributes !== []) {
                $meal->fill($attributes)->save();
            }

            // Items are replaced wholesale: the review screen always submits the
            // complete list, so diffing would add complexity with no benefit.
            if (array_key_exists('items', $data)) {
                $meal->items()->delete();
                $this->replaceItems($meal, $data['items']);
            }

            $meal->recalculateTotals();

            return $meal->load(['items', 'image']);
        });
    }

    public function delete(Meal $meal): void
    {
        DB::transaction(function () use ($meal) {
            // Keep the image rows but detach them, so a soft-deleted meal can be
            // restored later without losing its photo.
            $meal->images()->update(['meal_id' => null]);
            $meal->delete();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceItems(Meal $meal, array $items): void
    {
        foreach (array_values($items) as $position => $item) {
            $lockedMacros = array_values(array_intersect(
                $item['locked_macros'] ?? [],
                \App\Models\MealItem::MACRO_FIELDS,
            ));

            $meal->items()->create([
                'name' => $item['name'],
                'brand' => $item['brand'] ?? null,
                'portion_amount' => $item['portion_amount'],
                'portion_unit' => $item['portion_unit'],
                'base_portion_amount' => $item['base_portion_amount'] ?? null,
                'base_calories' => $item['base_calories'] ?? null,
                'base_protein' => $item['base_protein'] ?? null,
                'base_carbs' => $item['base_carbs'] ?? null,
                'base_fat' => $item['base_fat'] ?? null,
                'calories' => (int) round((float) $item['calories']),
                'protein' => $item['protein'],
                'carbs' => $item['carbs'],
                'fat' => $item['fat'],
                'confidence' => $item['confidence'] ?? null,
                'is_ai_generated' => (bool) ($item['is_ai_generated'] ?? false),
                'was_edited' => (bool) ($item['was_edited'] ?? false),
                'locked_macros' => $lockedMacros === [] ? null : $lockedMacros,
                'position' => $position,
            ]);
        }
    }

    /**
     * Attach a previously uploaded image, but only if it belongs to this user
     * and is not already claimed by another meal.
     */
    private function attachImage(User $user, Meal $meal, int $mealImageId): void
    {
        $image = MealImage::query()
            ->where('id', $mealImageId)
            ->where('user_id', $user->id)
            ->whereNull('meal_id')
            ->first();

        $image?->update(['meal_id' => $meal->id]);
    }

    private function resolveConsumedAt(User $user, ?string $value): Carbon
    {
        if ($value === null || trim($value) === '') {
            return Carbon::now();
        }

        // Submitted as an ISO-8601 instant; stored in UTC.
        return Carbon::parse($value)->utc();
    }

    private function timezone(User $user): string
    {
        return $user->timezone ?: 'UTC';
    }
}
