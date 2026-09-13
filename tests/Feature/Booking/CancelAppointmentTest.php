<?php

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Payment;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * B7 and B8 — US-014 cancellation, and the command that retires dead holds.
 */
beforeEach(function (): void {
    $this->timezone = config('nadara.clinic_timezone');
    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00', $this->timezone));

    $this->patient = User::factory()->patient()->create();
    $this->doctor = User::factory()->doctor()->create();

    $this->slotStart = CarbonImmutable::parse('2026-09-20 10:00', $this->timezone);

    $this->appointment = Appointment::factory()->approved()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'start_at' => $this->slotStart,
        'end_at' => $this->slotStart->addMinutes(30),
    ]);
});

it('cancels a booking outside the notice window', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.cancel', $this->appointment), ['reason' => 'Travelling.'])
        ->assertOk()
        ->assertJsonPath('data.status', AppointmentStatus::Cancelled->value);

    $this->appointment->refresh();

    expect($this->appointment->cancelled_at)->not->toBeNull()
        ->and($this->appointment->cancellation_reason)->toBe('Travelling.')
        ->and($this->appointment->hold_expires_at)->toBeNull();
});

it('frees the slot immediately for the next patient', function (): void {
    DoctorSchedule::factory()->create([
        'doctor_id' => $this->doctor->id,
        'weekday' => $this->slotStart->dayOfWeek,
        'starts_at' => '09:00:00',
        'ends_at' => '17:00:00',
        'slot_minutes' => 30,
    ]);

    $slotsBefore = collect(app(AvailabilityService::class)->slotsFor($this->doctor, $this->slotStart))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slotsBefore['10:00']['available'])->toBeFalse();

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.cancel', $this->appointment))
        ->assertOk();

    $slotsAfter = collect(app(AvailabilityService::class)->slotsFor($this->doctor, $this->slotStart))
        ->keyBy(fn (array $slot): string => $slot['start_at']->format('H:i'));

    expect($slotsAfter['10:00']['available'])->toBeTrue();
});

it('flags a refund when the money was already verified', function (): void {
    Payment::factory()->create([
        'appointment_id' => $this->appointment->id,
        'patient_id' => $this->patient->id,
        'status' => PaymentStatus::Verified,
    ]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.cancel', $this->appointment))
        ->assertOk()
        ->assertJsonPath('data.refund_required', true);
});

it('does not flag a refund when nothing was paid', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.cancel', $this->appointment))
        ->assertOk()
        ->assertJsonPath('data.refund_required', false);
});

it('refuses a cancellation inside the notice window', function (): void {
    $this->travelTo($this->slotStart->subHours(2));

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.cancel', $this->appointment))
        ->assertStatus(422)
        ->assertJsonValidationErrors('start_at');

    expect($this->appointment->refresh()->status)->toBe(AppointmentStatus::Approved);
});

it('refuses to cancel an appointment that is already closed', function (): void {
    $this->appointment->update(['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.cancel', $this->appointment))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('refuses a cancellation by anyone but the booking patient', function (): void {
    $this->actingAs(User::factory()->patient()->create())
        ->postJson(route('api.v1.appointments.cancel', $this->appointment))
        ->assertForbidden();

    $this->actingAs($this->doctor)
        ->postJson(route('api.v1.appointments.cancel', $this->appointment))
        ->assertForbidden();
});

/**
 * Sprint acceptance gate 2.
 */
it('expires a lapsed hold when the scheduled command runs', function (): void {
    $lapsed = Appointment::factory()->holdExpired()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $live = Appointment::factory()->pendingPayment()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->artisan('appointments:release-expired-holds')->assertSuccessful();

    expect($lapsed->refresh()->status)->toBe(AppointmentStatus::Expired)
        ->and($lapsed->hold_expires_at)->toBeNull()
        ->and($live->refresh()->status)->toBe(AppointmentStatus::PendingPayment);
});

it('leaves appointments that already left pending payment alone', function (): void {
    $reviewed = Appointment::factory()->awaitingReview()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'hold_expires_at' => now()->subDay(),
    ]);

    $this->artisan('appointments:release-expired-holds')->assertSuccessful();

    expect($reviewed->refresh()->status)->toBe(AppointmentStatus::AwaitingReview);
});
