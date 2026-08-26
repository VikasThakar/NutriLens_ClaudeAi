<?php

use App\Enums\ChatRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One turn in an AI Coach conversation.
     *
     * Ownership is expressed only through `conversation_id`: there is no
     * user_id here to drift out of step with the conversation's. Every query
     * therefore reaches a message through a conversation the caller owns.
     *
     * The provider and model are recorded on assistant turns so a stored
     * conversation can be audited later — including whether a reply came from
     * the offline `fake` driver.
     */
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete();

            $table->enum('role', ChatRole::values());
            $table->text('content');

            // Short follow-up prompts the UI offers as chips. Assistant only.
            $table->json('suggestions')->nullable();

            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
