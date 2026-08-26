<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI Coach chat thread.
 *
 * A conversation stores only what the user and the coach said. The nutrition
 * context behind a reply is deliberately *not* stored: it is rebuilt from the
 * user's live meals on every request, so a thread reopened tomorrow is
 * answered against tomorrow's numbers rather than yesterday's.
 */
class AiConversation extends Model
{
    /** @use HasFactory<\Database\Factories\AiConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'last_message_at',
        'message_count',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AiChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'conversation_id')
            ->orderBy('id');
    }

    /**
     * Newest activity first. A conversation with no messages yet falls back to
     * when it was created, so a freshly started chat still sorts to the top.
     *
     * @param  Builder<AiConversation>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('id');
    }
}
