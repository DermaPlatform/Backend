<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Appointment::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'doctor'),
            ],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'start_at' => [
                'required',
                'date',
                'after:now',
                'before:'.now()->addDays((int) config('nadara.max_booking_days_ahead'))->toDateTimeString(),
            ],
        ];
    }
}
