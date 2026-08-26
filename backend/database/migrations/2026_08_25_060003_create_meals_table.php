<?php

use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Enums\MealType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Macro totals are denormalised onto the meal so the Today dashboard can
     * sum a day without touching meal_items. They are recalculated from
     * meal_items whenever items change.
     */
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->enum('meal_type', MealType::values())->default(MealType::Snack->value);
            $table->enum('source', MealSource::values())->default(MealSource::Manual->value);
            $table->enum('status', MealStatus::values())->default(MealStatus::Logged->value);

            $table->timestamp('consumed_at');
            $table->date('consumed_on');   // denormalised local date, indexed for daily lookups

            $table->unsignedInteger('total_calories')->default(0);
            $table->decimal('total_protein', 8, 2)->default(0);
            $table->decimal('total_carbs', 8, 2)->default(0);
            $table->decimal('total_fat', 8, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'consumed_on']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};