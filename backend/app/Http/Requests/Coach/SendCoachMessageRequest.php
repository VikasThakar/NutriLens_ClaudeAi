<?php

namespace App\Http\Requests\Coach;

use App\Services\AI\CoachService;
use Illuminate\Foundation\Http\FormRequest;

class SendCoachMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership of the conversation is enforced by the policy in the
        // controller; this only asserts that somebody is signed in.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * A question, not a document. The ceiling keeps one request from
             * dominating the token budget, and `string` rather than `array`
             * means there is no way to smuggle structured content — or extra
             * roles — into the prompt.
             */
            'message' => ['required', 'string', 'min:1', 'max:'.CoachService::MAX_MESSAGE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Type a question for your coach.',
            'message.max' => 'That question is too long — please shorten it to '
                .CoachService::MAX_MESSAGE_LENGTH.' characters or fewer.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }
}
