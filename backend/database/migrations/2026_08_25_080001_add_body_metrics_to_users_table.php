<?php

use App\Enums\ActivityLevel;
use App\Enums\BiologicalSex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inputs for the optional goal calculator. All nullable: a user who never
     * opens the calculator never has any of this stored. They are kept only so
     * re-opening the calculator does not mean re-typing everything.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->nullable()->after('timezone');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('age');
            $table->decimal('weight_kg', 5, 1)->nullable()->after('height_cm');
            $table->enum('activity_level', ActivityLevel::values())->nullable()->after('weight_kg');
            $table->enum('biological_sex', BiologicalSex::values())->nullable()->after('activity_level');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'age',
                'height_cm',
                'weight_kg',
                'activity_level',
                'biological_sex',
            ]);
        });
    }
};
