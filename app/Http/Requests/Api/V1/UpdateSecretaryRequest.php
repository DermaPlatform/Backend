<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateSecretaryRequest extends FormRequest
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
        $secretary = $this->route('secretary');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => [
                'sometimes', 'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($secretary),
            ],
            'phone' => [
                'sometimes', 'required', 'string', 'regex:/^\+?[0-9\s\-()]{7,20}$/', 'max:20',
                Rule::unique('users', 'phone')->ignore($secretary),
            ],
            'password' => ['sometimes', 'required', 'string', Password::defaults()],
        ];
    }

    /**
     * Normalize the email so lookups and the unique rule stay case-insensitive.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : null,
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : null,
            'phone' => is_string($this->input('phone')) ? trim($this->input('phone')) : null,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone field must be a valid phone number.',
        ];
    }
}
