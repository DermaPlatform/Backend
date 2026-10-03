<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhoneAppointmentRequest extends FormRequest
{
    /**
     * Route access is already restricted to staff by the role middleware, and
     * the controller confines each of them to their own doctor.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The patient is identified by phone: it is what the secretary has on
     * the line, and it is unique. The name is only used when the number is
     * new, so a caller who already has an account keeps their own.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patient_phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-()]{7,20}$/', 'max:20'],
            'patient_name' => ['required', 'string', 'min:2', 'max:255'],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'start_at' => [
                'required',
                'date',
                'after:now',
                'before:'.now()->addDays((int) config('nadara.max_booking_days_ahead'))->toDateTimeString(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'patient_name' => is_string($this->input('patient_name')) ? trim($this->input('patient_name')) : null,
            'patient_phone' => is_string($this->input('patient_phone')) ? trim($this->input('patient_phone')) : null,
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_phone.regex' => 'The patient phone field must be a valid phone number.',
        ];
    }
}
