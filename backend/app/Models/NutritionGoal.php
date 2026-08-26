<?php

namespace App\Models;

use App\Enums\GoalSource;
use App\Enums\GoalType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NutritionGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'goal_type',
        'calorie_target',
        'protein_target',
        'carb_target',
        'fat_target',
        'source',
        'estimated_maintenance_calories',
        'is_active',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'goal_type' => GoalType::class,
            'calorie_target' => 'integer',
            'protein_target' => 'integer',
            'carb_target' => 'integer',
            'fat_target' => 'integer',
            'source' => GoalSource::class,
            'estimated_maintenance_calories' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<NutritionGoal> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
