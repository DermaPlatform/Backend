<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAppointmentsRequest extends FormRequest
{
    /**
     * The shortcuts a calendar screen asks for most often.
     *
     * @var array<int, string>
     */
    public const PERIODS = ['today', 'tomorrow', 'week', 'month'];

    /**
     * Every role may list appointments; the controller narrows what each sees.
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
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'period' => ['nullable', Rule::in(self::PERIODS), 'prohibits:from,to'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'doctor_id' => [
                'nullable',
                Rule::prohibitedIf(fn (): bool => $this->user()->role !== UserRole::Admin),
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Doctor->value),
            ],
        ];
    }

    /**
     * The half open [start, end) window the appointments must start in, in UTC.
     *
     * Days are cut on the clinic wall clock, so "today" means the clinic's
     * today rather than UTC's. The week runs Saturday to Friday, and both week
     * and month cover the whole calendar unit, days already past included.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function startWindow(): array
    {
        $timezone = config('nadara.clinic_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        [$start, $end] = match ($this->string('period')->value()) {
            'today' => [$today, $today->addDay()],
            'tomorrow' => [$today->addDay(), $today->addDays(2)],
            'week' => [
                $weekStart = $today->startOfWeek(CarbonImmutable::SATURDAY),
                $weekStart->addWeek(),
            ],
            'month' => [$today->startOfMonth(), $today->startOfMonth()->addMonth()],
            default => [
                $this->filled('from') ? CarbonImmutable::parse($this->string('from')->value(), $timezone) : null,
                $this->filled('to') ? CarbonImmutable::parse($this->string('to')->value(), $timezone)->addDay() : null,
            ],
        };

        return [$start?->utc(), $end?->utc()];
    }
}
