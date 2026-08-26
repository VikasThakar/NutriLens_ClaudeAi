<?php

namespace App\Http\Resources;

use App\Models\AiChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiChatMessage */
class AiChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'content' => $this->content,
            // Short follow-up prompts the UI offers as chips. Assistant turns
            // only; always an array so the client never has to null-check it.
            'suggestions' => $this->suggestions ?? [],
            'ai_provider' => $this->ai_provider,
            'ai_model' => $this->ai_model,
            /*
             * True when this reply came from the offline driver rather than a
             * language model. Surfaced so the UI can label a demo response
             * honestly instead of passing it off as an AI answer.
             */
            'is_simulated' => $this->ai_provider === 'fake',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
