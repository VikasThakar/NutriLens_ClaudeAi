<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class AnalyzeNutritionImageRequest extends FormRequest
{
    /** Maximum upload size in kilobytes. */
    public const MAX_KILOBYTES = 12288; // 12 MB

    /**
     * Authorisation is the API key middleware's job — by the time a request
     * reaches here the key has already been resolved and checked.
     */
    public function authorize(): bool
    {
        return $this->attributes->get('api_key') !== null;
    }

    /**
     * `File::image()` inspects the *decoded* image rather than trusting the
     * client-supplied Content-Type or the filename extension, so a renamed
     * executable or a text file claiming `image/jpeg` is rejected here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(self::MAX_KILOBYTES),
                Rule::dimensions()
                    ->minWidth(64)
                    ->minHeight(64)
                    ->maxWidth(12000)
                    ->maxHeight(12000),
            ],
            // Optional passthrough so a partner can label the result in their
            // own system. It is echoed back, never used to influence the model.
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megabytes = (int) round(self::MAX_KILOBYTES / 1024);

        return [
            'image.required' => 'An image file is required in the `image` field.',
            'image.image' => 'The uploaded file is not a valid image.',
            'image.mimes' => 'Supported image formats are JPEG, PNG and WebP.',
            'image.mimetypes' => 'Supported image formats are JPEG, PNG and WebP.',
            'image.max' => "The image exceeds the {$megabytes} MB limit.",
            'image.dimensions' => 'The image must be at least 64x64 pixels and no larger than 12000x12000.',
            'image.uploaded' => 'The upload did not complete. It may exceed the size this server accepts.',
        ];
    }
}
