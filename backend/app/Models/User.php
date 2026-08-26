<?php

namespace App\Models;

use App\Enums\ActivityLevel;
use App\Enums\BiologicalSex;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
        'timezone',
        'onboarded_at',
        'age',
        'height_cm',
        'weight_kg',
        'activity_level',
        'biological_sex',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'password' => 'hashed',
            'age' => 'integer',
            'height_cm' => 'integer',
            'weight_kg' => 'float',
            'activity_level' => ActivityLevel::class,
            'biological_sex' => BiologicalSex::class,
        ];
    }

    /**
     * The goal currently driving the user's dashboard.
     *
     * @return HasOne<NutritionGoal, $this>
     */
    public function activeNutritionGoal(): HasOne
    {
        return $this->hasOne(NutritionGoal::class)
            ->where('is_active', true)
            ->latestOfMany();
    }

    /** @return HasMany<NutritionGoal, $this> */
    public function nutritionGoals(): HasMany
    {
        return $this->hasMany(NutritionGoal::class);
    }

    /** @return HasMany<Meal, $this> */
    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    /** @return HasMany<MealImage, $this> */
    public function mealImages(): HasMany
    {
        return $this->hasMany(MealImage::class);
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /** @return HasMany<WeeklyInsight, $this> */
    public function weeklyInsights(): HasMany
    {
        return $this->hasMany(WeeklyInsight::class);
    }

    /** @return HasMany<AiConversation, $this> */
    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarded_at !== null;
    }

    /**
     * The timezone every date boundary in the app is resolved against.
     * Streaks, daily totals and week windows all use this — never the
     * server's clock, which would put a user in Auckland a day out.
     */
    public function tz(): string
    {
        return $this->timezone ?: 'UTC';
    }

    /** Midnight today, in the user's own timezone. */
    public function today(): Carbon
    {
        return Carbon::now($this->tz())->startOfDay();
    }

    /** Whether enough is stored to pre-fill the goal calculator. */
    public function hasBodyMetrics(): bool
    {
        return $this->age !== null
            && $this->height_cm !== null
            && $this->weight_kg !== null;
    }
}
