<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One AI Coach chat thread, owned by exactly one user.
     *
     * `last_message_at` is denormalised so the conversation list can be
     * ordered and rendered without touching ai_chat_messages, which is the
     * hot table. `message_count` exists for the same reason.
     */
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Derived from the first user message, so a thread is recognisable
            // in the list without asking the model to name it.
            $table->string('title')->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
