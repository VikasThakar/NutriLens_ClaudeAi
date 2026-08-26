<?php

use App\Enums\GoalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user may accumulate a history of goals over time; exactly one row per
     * user carries is_active = true and drives the Today dashboard.
     */
    public function up(): void
    {
        Schema::create('nutrition_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('goal_type', GoalType::values())->default(GoalType::ImproveNutrition->value);

            $table->unsignedSmallInteger('calorie_target');
            $table->unsignedSmallInteger('protein_target');   // grams
            $table->unsignedSmallInteger('carb_target');      // grams
            $table->unsignedSmallInteger('fat_target');       // grams

            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_goals');
    }
};