<?php

namespace Database\Factories;

use App\Enums\ChatRole;
use App\Models\AiChatMessage;
use App\Models\AiConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatMessage>
 */
class AiChatMessageFactory extends Factory
{
    protected $model = AiChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => AiConversation::factory(),
            'role' => ChatRole::User,
            'content' => 'What should I eat next?',
        ];
    }

    public function fromUser(string $content = 'What should I eat next?'): static
    {
        return $this->state(fn () => [
            'role' => ChatRole::User,
            'content' => $content,
        ]);
    }

    public function fromAssistant(string $content = 'You have 580 kcal left today.'): static
    {
        return $this->state(fn () => [
            'role' => ChatRole::Assistant,
            'content' => $content,
            'ai_provider' => 'fake',
            'ai_model' => 'nutrilens-fake-coach',
        ]);
    }
}
