<?php

namespace App\Http\Requests\Meal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class AnalyzeMealImageRequest extends FormRequest
{
    /** Maximum upload size in kilobytes. */
    public const MAX_KILOBYTES = 12288; // 12 MB

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Front-end validation is a convenience; this is the real gate.
     * `File::image()` inspects the *decoded* image rather than trusting the
     * client-supplied Content-Type or the filename extension, so a renamed
     * .exe or a text file with an image mime type is rejected here.
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megabytes = (int) round(self::MAX_KILOBYTES / 1024);

        return [
            'image.required' => 'Please choose a photo to analyse.',
            'image.image' => 'That file is not a valid image.',
            'image.mimes' => 'Please upload a JPEG, PNG or WebP image. HEIC photos are not supported yet.',
            'image.mimetypes' => 'Please upload a JPEG, PNG or WebP image. HEIC photos are not supported yet.',
            'image.max' => "That image is too large. Please keep it under {$megabytes} MB.",
            'image.dimensions' => 'That image is too small to analyse. Please use a larger photo.',
            'image.uploaded' => 'The upload did not complete. It may be larger than this server accepts.',
        ];
    }
}
