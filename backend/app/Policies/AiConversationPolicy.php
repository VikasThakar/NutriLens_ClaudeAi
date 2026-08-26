<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;

/**
 * Conversations are strictly private to their owner.
 *
 * AiCoachController additionally scopes every lookup through
 * $user->aiConversations(); this policy is the second line of defence for any
 * route that resolves a conversation by id, and the only check that guards
 * messages — a message is reachable only through its conversation.
 */
class AiConversationPolicy
{
    public function view(User $user, AiConversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }

    public function update(User $user, AiConversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }

    public function delete(User $user, AiConversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }
}
