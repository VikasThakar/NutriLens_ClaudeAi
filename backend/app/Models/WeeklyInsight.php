<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-generated weekly summary per user per week.
 *
 * The aggregated numbers the narrative was written from are stored alongside
 * it, so the Insights screen renders without recomputing a week of meals and
 * the text can always be checked against the figures it describes.
 */
class WeeklyInsight extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'week_start',
        'week_end',
        'headline',
        'summary',
        'highlights',
        'recommendations',
        'comparison',
        'meals_logged',
        'days_logged',
        'days_close_to_target',
        'calorie_target',
        'avg_calories',
        'avg_protein',
        'avg_carbs',
        'avg_fat',
        'goal_adherence',
        'generated_at',
        'ai_provider',
        'ai_model',
        'data_hash',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'highlights' => 'array',
            'recommendations' => 'array',
            'comparison' => 'array',
            'meals_logged' => 'integer',
            'days_logged' => 'integer',
            'days_close_to_target' => 'integer',
            'calorie_target' => 'integer',
            'avg_calories' => 'integer',
            'avg_protein' => 'float',
            'avg_carbs' => 'float',
            'avg_fat' => 'float',
            'goal_adherence' => 'float',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Newest week first. @param Builder<WeeklyInsight> $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('week_start');
    }
}
