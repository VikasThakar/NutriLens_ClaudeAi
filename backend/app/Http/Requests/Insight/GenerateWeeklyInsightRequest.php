<?php

namespace App\Http\Requests\Insight;

use Illuminate\Foundation\Http\FormRequest;

class GenerateWeeklyInsightRequest extends FormRequest
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
            // Any date inside the wanted week; the service resolves it to that
            // week's Monday in the user's timezone.
            'date' => ['sometimes', 'date_format:Y-m-d'],
            // Ask for a fresh summary even though a stored one exists. The
            // client only offers this when the underlying data has changed.
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
