<?php

namespace App\Http\Requests\Coach;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional. Left blank, the thread is named after its first
            // message rather than spending an AI call on a title.
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
