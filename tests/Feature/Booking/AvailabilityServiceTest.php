<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\ScheduleException;
use App\Models\ScheduleException as Exception;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * B1 — slot generation. Exercised directly rather than through HTTP, because
 * this is arithmetic and a failure here should point at the arithmetic.
 *
 * Every date below is fixed. A relative date makes the suite depend on the
 * day it happens to run.
 */
beforeEach(function (): void {
    $this->service = app(AvailabilityService::class);

    // A Sunday, the first working day of the week in the clinic timezone.
    $this->date = CarbonImmutable::parse('2026-09-20');

    $this->doctor = User::factory()->doctor()->create();

    $this->schedule = DoctorSchedule::factory()->create([
        'doctor_id' => $this->doctor->id,
        'weekday' => $this->date->dayOfWeek,
        'starts_at' => '09:00:00',
        'ends_at' => '17:00:00',
        'slot_minutes' => 30,
    ]);
});

/**
 * Build an appointment on this doctor's calendar at a clinic wall clock time.
 */
function appointmentAt(string $from, string $to, array $attributes = []): Appointment
{
    $timezone = config('nadara.clinic_timezone');

    return Appointment::factory()->create([
        'doctor_id' => test()->doctor->id,
        'start_at' => CarbonImmutable::parse('2026-09-20 '.$from, $timezone),
        'end_at' => CarbonImmutable::parse('2026-09-20 '.$to, $timezone),
        ...$attributes,
    ]);
}

it('slices the working day into fixed slots', function (): void {
    $slots = $this->service->slotsFor($this->doctor, $this->date);

    expect($slots)->toHaveCount(16)
        ->and($slots[0]['start_at']->format('H:i'))->toBe('09:00')
        ->and($slots[0]['end_at']->format('H:i'))->toBe('09:30')
        ->and($slots[15]['start_at']->format('H:i'))->toBe('16:30')
        ->and($slots[15]['end_at']->format('H:i'))->toBe('17:00');
});

it('returns nothing on a weekday the doctor does not work', function (): void {
    $slots = $this->service->slotsFor($this->doctor, $this->date->addDay());

    expect($slots)->toBeEmpty();
});

it('ignores a schedule row that has been switched off', function (): void {
    $this->schedule->update(['is_active' => false]);

    expect($this->service->slotsFor($this->doctor, $this->date))->toBeEmpty();
});

it('slices every shift when a doctor works twice in one day', function (): void {
    DoctorSchedule::factory()->create([
        'doctor_id' => $this->doctor->id,
        'weekday' => $this->date->dayOfWeek,
        'starts_at' => '19:00:00',
        'ends_at' => '21:00:00',
        'slot_minutes' => 30,
    ]);

    expect($this->service->slotsFor($this->doctor, $this->date))->toHaveCount(20);
});

it('marks a slot taken by an approved appointment as unavailable', function (): void {
    appointmentAt('10:00', '10:30', ['status' => AppointmentStatus::Approved]);

    $slots = collect($this->service->slotsFor($this->doctor, $this->date))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slots['10:00']['available'])->toBeFalse()
        ->and($slots['09:30']['available'])->toBeTrue();
});

it('keeps a slot blocked while an unpaid hold is still running', function (): void {
    appointmentAt('10:00', '10:30', [
        'status' => AppointmentStatus::PendingPayment,
        'hold_expires_at' => now()->addMinutes(10),
    ]);

    $slots = collect($this->service->slotsFor($this->doctor, $this->date))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slots['10:00']['available'])->toBeFalse();
});

it('frees a slot whose unpaid hold has lapsed', function (): void {
    appointmentAt('10:00', '10:30', [
        'status' => AppointmentStatus::PendingPayment,
        'hold_expires_at' => now()->subMinute(),
    ]);

    $slots = collect($this->service->slotsFor($this->doctor, $this->date))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slots['10:00']['available'])->toBeTrue();
});

it('leaves the slot starting the moment an appointment ends available', function (): void {
    appointmentAt('09:00', '09:30', ['status' => AppointmentStatus::Approved]);

    $slots = collect($this->service->slotsFor($this->doctor, $this->date))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slots['09:00']['available'])->toBeFalse()
        ->and($slots['09:30']['available'])->toBeTrue();
});

it('ignores an appointment that no longer holds its slot', function (): void {
    appointmentAt('10:00', '10:30', ['status' => AppointmentStatus::Cancelled]);

    $slots = collect($this->service->slotsFor($this->doctor, $this->date))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slots['10:00']['available'])->toBeTrue();
});

it('closes the whole day for a full day exception', function (): void {
    ScheduleException::factory()->fullDay()->create([
        'doctor_id' => $this->doctor->id,
        'date' => $this->date->toDateString(),
    ]);

    $slots = $this->service->slotsFor($this->doctor, $this->date);

    expect($slots)->toHaveCount(16)
        ->and(collect($slots)->every(fn (array $slot): bool => $slot['available'] === false))->toBeTrue();
});

it('closes only the affected slots for a partial exception', function (): void {
    Exception::factory()->create([
        'doctor_id' => $this->doctor->id,
        'date' => $this->date->toDateString(),
        'starts_at' => '12:00:00',
        'ends_at' => '13:00:00',
    ]);

    $slots = collect($this->service->slotsFor($this->doctor, $this->date))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slots['12:00']['available'])->toBeFalse()
        ->and($slots['12:30']['available'])->toBeFalse()
        ->and($slots['11:30']['available'])->toBeTrue()
        ->and($slots['13:00']['available'])->toBeTrue();
});

it('never offers a slot that has already passed today', function (): void {
    $timezone = config('nadara.clinic_timezone');

    $this->travelTo(CarbonImmutable::parse('2026-09-20 11:15', $timezone));

    $slots = collect($this->service->slotsFor($this->doctor, $this->date))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slots['09:00']['available'])->toBeFalse()
        ->and($slots['11:00']['available'])->toBeFalse()
        ->and($slots['11:30']['available'])->toBeTrue();
});

it('builds slots on clinic wall clock time, not UTC', function (): void {
    $slots = $this->service->slotsFor($this->doctor, $this->date);

    expect($slots[0]['start_at']->timezone->getName())->toBe(config('nadara.clinic_timezone'))
        ->and($slots[0]['start_at']->utc()->format('H:i'))->not->toBe('09:00');
});
