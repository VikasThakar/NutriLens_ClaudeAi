<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `title` becomes `meal_name` so one vocabulary runs the whole way through:
     * the AI returns `meal_name`, the column stores it, the API exposes it.
     */
    public function up(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->renameColumn('title', 'meal_name');
        });

        Schema::table('meals', function (Blueprint $table) {
            // Overall model confidence for the analysis that produced this meal.
            // Null for manually entered meals.
            $table->decimal('ai_confidence', 4, 3)->nullable()->after('status');
            $table->string('ai_provider', 32)->nullable()->after('ai_confidence');
            $table->string('ai_model', 64)->nullable()->after('ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->dropColumn(['ai_confidence', 'ai_provider', 'ai_model']);
        });

        Schema::table('meals', function (Blueprint $table) {
            $table->renameColumn('meal_name', 'title');
        });
    }
};
