<?php

use App\Enums\AnalysisStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A photo uploaded for analysis. meal_id is nullable because an image is
     * uploaded *before* the meal it will produce exists — it is attached once
     * the user confirms the analysis.
     */
    public function up(): void
    {
        Schema::create('meal_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_id')->nullable()->constrained()->nullOnDelete();

            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->enum('analysis_status', AnalysisStatus::values())
                ->default(AnalysisStatus::Pending->value);
            $table->json('analysis_payload')->nullable();   // raw model response, for auditing
            $table->text('analysis_error')->nullable();
            $table->timestamp('analyzed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'analysis_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_images');
    }
};