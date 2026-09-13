<?php

use App\Enums\AppointmentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * B2 and B4 — US-010, booking an appointment.
 */
beforeEach(function (): void {
    $this->timezone = config('nadara.clinic_timezone');

    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00', $this->timezone));

    $this->patient = User::factory()->patient()->create();
    $this->doctor = User::factory()->doctor()->create();
    $this->service = Service::factory()->create();

    $this->doctor->offeredServices()->attach($this->service->id, [
        'price' => 150.00,
        'duration_minutes' => 45,
        'is_active' => true,
    ]);

    // A Sunday in the future.
    $this->slotStart = CarbonImmutable::parse('2026-09-20 10:00', $this->timezone);

    DoctorSchedule::factory()->create([
        'doctor_id' => $this->doctor->id,
        'weekday' => $this->slotStart->dayOfWeek,
        'starts_at' => '09:00:00',
        'ends_at' => '17:00:00',
        'slot_minutes' => 30,
    ]);
});

function bookingPayload(array $overrides = []): array
{
    return [
        'doctor_id' => test()->doctor->id,
        'service_id' => test()->service->id,
        'start_at' => test()->slotStart->toIso8601String(),
        ...$overrides,
    ];
}

it('holds a slot for the patient pending payment', function (): void {
    $response = $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload());

    $response->assertCreated()
        ->assertJsonPath('data.status', AppointmentStatus::PendingPayment->value);

    $appointment = Appointment::firstOrFail();

    expect($appointment->patient_id)->toBe($this->patient->id)
        ->and($appointment->doctor_id)->toBe($this->doctor->id)
        ->and($appointment->start_at->eq($this->slotStart))->toBeTrue()
        ->and($appointment->hold_expires_at)->not->toBeNull();
});

it('takes the duration from the doctor rather than the service', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertCreated();

    $appointment = Appointment::firstOrFail();

    expect($appointment->start_at->diffInMinutes($appointment->end_at))->toBe(45.0);
});

it('copies the doctor price onto the appointment', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertCreated();

    expect(Appointment::firstOrFail()->price)->toBe('150.00');
});

/**
 * Sprint acceptance gate 3.
 */
it('keeps the old price when the doctor later changes their rate', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertCreated();

    $this->doctor->offeredServices()->updateExistingPivot($this->service->id, ['price' => 999.00]);

    expect(Appointment::firstOrFail()->price)->toBe('150.00');
});

/**
 * Sprint acceptance gate 1. True parallelism is not reachable from the test
 * runner, so this asserts the contract the lock exists to guarantee: the
 * second attempt on the same slot is refused, and refused as a conflict.
 */
it('refuses a second booking of the same slot with a conflict', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertCreated();

    $other = User::factory()->patient()->create();

    $this->actingAs($other)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertStatus(409);

    expect(Appointment::count())->toBe(1);
});

it('refuses a booking that overlaps an existing one only partly', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertCreated();

    $this->actingAs(User::factory()->patient()->create())
        ->postJson(route('api.v1.appointments.store'), bookingPayload([
            'start_at' => $this->slotStart->addMinutes(30)->toIso8601String(),
        ]))
        ->assertStatus(409);
});

it('allows a booking that starts exactly when the previous one ends', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertCreated();

    $this->actingAs(User::factory()->patient()->create())
        ->postJson(route('api.v1.appointments.store'), bookingPayload([
            'start_at' => $this->slotStart->addMinutes(45)->toIso8601String(),
        ]))
        ->assertCreated();

    expect(Appointment::count())->toBe(2);
});

it('lets a lapsed hold free the slot for someone else', function (): void {
    Appointment::factory()->holdExpired()->create([
        'doctor_id' => $this->doctor->id,
        'service_id' => $this->service->id,
        'start_at' => $this->slotStart,
        'end_at' => $this->slotStart->addMinutes(45),
    ]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertCreated();
});

it('rejects a service the doctor does not offer', function (): void {
    $other = Service::factory()->create();

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload(['service_id' => $other->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('service_id');
});

it('rejects a doctor who has switched appointments off', function (): void {
    $this->doctor->doctorProfile->update(['accepts_appointments' => false]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('doctor_id');
});

it('rejects a doctor whose subscription has lapsed', function (): void {
    $this->doctor->doctorProfile->update([
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_ends_at' => now()->subDay(),
    ]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertStatus(422);
});

it('rejects a deactivated doctor', function (): void {
    $this->doctor->update(['is_active' => false]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertStatus(422);
});

it('rejects a time in the past', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.store'), bookingPayload([
            'start_at' => now()->subHour()->toIso8601String(),
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('start_at');
});

it('refuses to book for anyone but a patient', function (): void {
    $this->actingAs($this->doctor)
        ->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertForbidden();
});

it('requires authentication', function (): void {
    $this->postJson(route('api.v1.appointments.store'), bookingPayload())
        ->assertUnauthorized();
});
