<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealItem extends Model
{
    /** Macro fields a user can lock by editing them by hand. */
    public const MACRO_FIELDS = ['calories', 'protein', 'carbs', 'fat'];

    protected $fillable = [
        'meal_id',
        'name',
        'brand',
        'portion_amount',
        'portion_unit',
        'base_portion_amount',
        'base_calories',
        'base_protein',
        'base_carbs',
        'base_fat',
        'calories',
        'protein',
        'carbs',
        'fat',
        'fiber',
        'sugar',
        'sodium',
        'confidence',
        'is_ai_generated',
        'was_edited',
        'locked_macros',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'portion_amount' => 'float',
            'base_portion_amount' => 'float',
            'base_calories' => 'integer',
            'base_protein' => 'float',
            'base_carbs' => 'float',
            'base_fat' => 'float',
            'calories' => 'integer',
            'protein' => 'float',
            'carbs' => 'float',
            'fat' => 'float',
            'fiber' => 'float',
            'sugar' => 'float',
            'sodium' => 'float',
            'confidence' => 'float',
            'is_ai_generated' => 'boolean',
            'was_edited' => 'boolean',
            'locked_macros' => 'array',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Meal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    /**
     * True when the user has hand-edited this macro, meaning portion scaling
     * must leave it alone.
     */
    public function macroIsLocked(string $macro): bool
    {
        return in_array($macro, $this->locked_macros ?? [], true);
    }

    /** Whether an AI baseline exists to scale from. */
    public function hasBaseline(): bool
    {
        return $this->base_portion_amount !== null && $this->base_portion_amount > 0;
    }
}
