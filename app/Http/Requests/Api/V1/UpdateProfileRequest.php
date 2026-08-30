<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * The route is behind auth:sanctum, so any caller here owns the profile.
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($this->user()->id),
            ],
            'birth_date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                'before:today',
                'after:'.now()->subYears(config('nadara.patients.max_age'))->toDateString(),
            ],
            'gender' => ['sometimes', 'required', Rule::enum(Gender::class)],
        ];
    }

    /**
     * Accept the gender in any casing.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('gender'))) {
            $this->merge(['gender' => mb_strtolower(trim($this->input('gender')))]);
        }
    }
}
