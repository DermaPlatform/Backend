<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceImageRequest extends FormRequest
{
    /**
     * Route access is already restricted to admins by the role middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'image',
                'mimes:'.implode(',', config('nadara.uploads.image_mimes')),
                'max:'.config('nadara.uploads.image_max_kb'),
            ],
        ];
    }
}
