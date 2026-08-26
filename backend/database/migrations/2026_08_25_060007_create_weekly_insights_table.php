<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One AI-generated summary per user per week. Averages are stored
     * alongside the narrative so the Insights screen can render without
     * recomputing a week of meals.
     */
    public function up(): void
    {
        Schema::create('weekly_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('week_start');   // Monday
            $table->date('week_end');     // Sunday

            $table->text('summary')->nullable();
            $table->json('highlights')->nullable();
            $table->json('recommendations')->nullable();

            $table->unsignedInteger('meals_logged')->default(0);
            $table->unsignedInteger('days_logged')->default(0);
            $table->unsignedInteger('avg_calories')->default(0);
            $table->decimal('avg_protein', 8, 2)->default(0);
            $table->decimal('avg_carbs', 8, 2)->default(0);
            $table->decimal('avg_fat', 8, 2)->default(0);
            $table->decimal('goal_adherence', 5, 2)->nullable();  // percentage

            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_insights');
    }
};