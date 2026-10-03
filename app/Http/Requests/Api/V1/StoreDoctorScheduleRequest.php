<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDoctorScheduleRequest extends FormRequest
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
     * Times are clinic wall clock, as HH:MM. Weekday follows Carbon: 0 is
     * Sunday and 6 is Saturday.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weekday' => ['required', 'integer', 'between:0,6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The shift as the table stores it, with seconds on the times.
     *
     * @return array{weekday: int, starts_at: string, ends_at: string, slot_minutes: int, is_active: bool}
     */
    public function shift(): array
    {
        return [
            'weekday' => $this->integer('weekday'),
            'starts_at' => $this->string('starts_at')->value().':00',
            'ends_at' => $this->string('ends_at')->value().':00',
            'slot_minutes' => $this->integer('slot_minutes'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
