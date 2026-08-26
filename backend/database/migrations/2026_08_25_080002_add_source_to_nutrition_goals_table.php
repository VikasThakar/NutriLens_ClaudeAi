<?php

use App\Enums\GoalSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a goal's numbers came from, and — when the calculator produced
     * them — the maintenance estimate they were derived from. Both exist so
     * the Goals screen can be honest about which figures are estimates.
     */
    public function up(): void
    {
        Schema::table('nutrition_goals', function (Blueprint $table) {
            $table->enum('source', GoalSource::values())
                ->default(GoalSource::Manual->value)
                ->after('fat_target');

            $table->unsignedSmallInteger('estimated_maintenance_calories')
                ->nullable()
                ->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_goals', function (Blueprint $table) {
            $table->dropColumn(['source', 'estimated_maintenance_calories']);
        });
    }
};
