<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversation>
 *
 * For tests that need a thread to already exist — ownership checks, the
 * conversation list, and continuing an existing chat. Tests that exercise
 * *creating* a thread still go through POST /api/ai-coach/conversations.
 */
class AiConversationFactory extends Factory
{
    protected $model = AiConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->randomElement([
                'What should I eat next?',
                'Help me hit my protein goal',
                'Analyze my week',
            ]),
            'last_message_at' => null,
            'message_count' => 0,
        ];
    }
}
