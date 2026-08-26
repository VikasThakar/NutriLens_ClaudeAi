<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 additions to the weekly insight record.
     *
     * `data_hash` is the important one: it fingerprints the aggregated numbers
     * the insight was generated from, so asking for the same week again with
     * unchanged data reuses the stored summary instead of paying for another
     * AI call.
     */
    public function up(): void
    {
        Schema::table('weekly_insights', function (Blueprint $table) {
            $table->string('headline')->nullable()->after('week_end');

            // The previous week's aggregates, when there were enough of them
            // to compare against. Null means "no comparable prior week".
            $table->json('comparison')->nullable()->after('recommendations');

            // Days in the week whose calories landed inside the tolerance band
            // around the target — the transparent figure behind
            // "days close to your target".
            $table->unsignedTinyInteger('days_close_to_target')->default(0)->after('days_logged');
            $table->unsignedSmallInteger('calorie_target')->nullable()->after('days_close_to_target');

            $table->string('ai_provider', 32)->nullable()->after('generated_at');
            $table->string('ai_model', 64)->nullable()->after('ai_provider');
            $table->string('data_hash', 64)->nullable()->after('ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_insights', function (Blueprint $table) {
            $table->dropColumn([
                'headline',
                'comparison',
                'days_close_to_target',
                'calorie_target',
                'ai_provider',
                'ai_model',
                'data_hash',
            ]);
        });
    }
};
