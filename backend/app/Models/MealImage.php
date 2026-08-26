<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealImage extends Model
{
    protected $fillable = [
        'user_id',
        'meal_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'analysis_status',
        'analysis_payload',
        'analysis_error',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis_status' => AnalysisStatus::class,
            'analysis_payload' => 'array',
            'analyzed_at' => 'datetime',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Meal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }
}