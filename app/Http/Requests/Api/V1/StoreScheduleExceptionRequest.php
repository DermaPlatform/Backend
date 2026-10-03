<?php

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleExceptionRequest extends FormRequest
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
     * Leave both times out for a whole day off. Give both to close a window
     * inside the day; one without the other means nothing.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.CarbonImmutable::now(config('nadara.clinic_timezone'))->toDateString(),
            ],
            'starts_at' => ['nullable', 'required_with:ends_at', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_with:starts_at', 'date_format:H:i', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function startsAt(): ?string
    {
        return $this->filled('starts_at') ? $this->string('starts_at')->value().':00' : null;
    }

    public function endsAt(): ?string
    {
        return $this->filled('ends_at') ? $this->string('ends_at')->value().':00' : null;
    }
}
