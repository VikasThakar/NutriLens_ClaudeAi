<?php

namespace App\Models;

use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Enums\MealType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'meal_name',
        'meal_type',
        'source',
        'status',
        'ai_confidence',
        'ai_provider',
        'ai_model',
        'consumed_at',
        'consumed_on',
        'total_calories',
        'total_protein',
        'total_carbs',
        'total_fat',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'meal_type' => MealType::class,
            'source' => MealSource::class,
            'status' => MealStatus::class,
            'ai_confidence' => 'float',
            'consumed_at' => 'datetime',
            'consumed_on' => 'date',
            'total_calories' => 'integer',
            'total_protein' => 'float',
            'total_carbs' => 'float',
            'total_fat' => 'float',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MealItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class)->orderBy('position');
    }

    /** @return HasMany<MealImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(MealImage::class);
    }

    /**
     * The photo shown as this meal's thumbnail. A meal has at most one today,
     * but the relation is kept plural so re-analysis can add more later.
     *
     * @return HasOne<MealImage, $this>
     */
    public function image(): HasOne
    {
        return $this->hasOne(MealImage::class)->latestOfMany();
    }

    /**
     * Recalculate the denormalised macro totals from this meal's items.
     */
    public function recalculateTotals(): void
    {
        $items = $this->items()->get();

        $this->forceFill([
            'total_calories' => (int) $items->sum('calories'),
            'total_protein' => round((float) $items->sum('protein'), 2),
            'total_carbs' => round((float) $items->sum('carbs'), 2),
            'total_fat' => round((float) $items->sum('fat'), 2),
        ])->save();
    }

    /** @param Builder<Meal> $query */
    public function scopeLogged(Builder $query): void
    {
        $query->where('status', MealStatus::Logged);
    }

    /** @param Builder<Meal> $query */
    public function scopeOnDate(Builder $query, string $date): void
    {
        $query->whereDate('consumed_on', $date);
    }
}
