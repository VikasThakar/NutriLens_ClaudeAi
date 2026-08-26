<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // quantity/unit → portion_amount/portion_unit, matching the AI payload
        // and the API surface.
        Schema::table('meal_items', function (Blueprint $table) {
            $table->renameColumn('quantity', 'portion_amount');
            $table->renameColumn('unit', 'portion_unit');
        });

        Schema::table('meal_items', function (Blueprint $table) {
            /*
             * The AI's original estimate, kept so portion changes can be scaled
             * from a stable baseline instead of compounding rounding errors
             * across successive edits. Null for manually entered items.
             */
            $table->decimal('base_portion_amount', 8, 2)->nullable()->after('portion_unit');
            $table->unsignedInteger('base_calories')->nullable()->after('base_portion_amount');
            $table->decimal('base_protein', 8, 2)->nullable()->after('base_calories');
            $table->decimal('base_carbs', 8, 2)->nullable()->after('base_protein');
            $table->decimal('base_fat', 8, 2)->nullable()->after('base_carbs');

            /*
             * Macro fields the user has hand-edited, e.g. ["calories","protein"].
             * Portion scaling skips these so a manual value is never silently
             * overwritten.
             */
            $table->json('locked_macros')->nullable()->after('was_edited');
        });
    }

    public function down(): void
    {
        Schema::table('meal_items', function (Blueprint $table) {
            $table->dropColumn([
                'base_portion_amount',
                'base_calories',
                'base_protein',
                'base_carbs',
                'base_fat',
                'locked_macros',
            ]);
        });

        Schema::table('meal_items', function (Blueprint $table) {
            $table->renameColumn('portion_amount', 'quantity');
            $table->renameColumn('portion_unit', 'unit');
        });
    }
};
