<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One recognised food within a meal — "grilled chicken breast", "brown
     * rice", "olive oil dressing". AI produces these; the user edits them.
     */
    public function up(): void
    {
        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('brand')->nullable();

            $table->decimal('quantity', 8, 2)->default(1);
            $table->string('unit', 32)->default('serving');   // g, ml, cup, slice, serving…

            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein', 8, 2)->default(0);
            $table->decimal('carbs', 8, 2)->default(0);
            $table->decimal('fat', 8, 2)->default(0);
            $table->decimal('fiber', 8, 2)->nullable();
            $table->decimal('sugar', 8, 2)->nullable();
            $table->decimal('sodium', 8, 2)->nullable();      // milligrams

            // Populated by the AI pipeline; null for hand-entered items.
            $table->decimal('confidence', 4, 3)->nullable();  // 0.000 – 1.000
            $table->boolean('is_ai_generated')->default(false);
            $table->boolean('was_edited')->default(false);

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['meal_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_items');
    }
};