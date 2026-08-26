<?php

namespace App\Models;

use App\Enums\ChatRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in an AI Coach conversation.
 *
 * There is no user_id column on purpose: ownership belongs to the
 * conversation, and duplicating it here would create a second source of truth
 * that could drift.
 */
class AiChatMessage extends Model
{
    /** @use HasFactory<\Database\Factories\AiChatMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'suggestions',
        'ai_provider',
        'ai_model',
    ];

    protected function casts(): array
    {
        return [
            'role' => ChatRole::class,
            'suggestions' => 'array',
        ];
    }

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
