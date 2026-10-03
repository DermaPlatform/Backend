<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * US-026, US-013 and US-015 — the clinic books for a caller, cancels with a
 * reason, and resizes an appointment.
 */
beforeEach(function (): void {
    $this->timezone = config('nadara.clinic_timezone');

    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00', $this->timezone));

    $this->doctor = User::factory()->doctor()->create();
    $this->secretary = User::factory()->secretary($this->doctor)->create();
    $this->admin = User::factory()->admin()->create();
    $this->otherDoctor = User::factory()->doctor()->create();
    $this->service = Service::factory()->create();

    $this->doctor->offeredServices()->attach($this->service->id, [
        'price' => 150.00,
        'duration_minutes' => 30,
        'is_active' => true,
    ]);

    $this->slotStart = CarbonImmutable::parse('2026-09-20 10:00', $this->timezone);
});

function phonePayload(array $overrides = []): array
{
    return [
        'patient_name' => 'أحمد يوسف',
        'patient_phone' => '+970599123456',
        'service_id' => test()->service->id,
        'start_at' => test()->slotStart->toIso8601String(),
        ...$overrides,
    ];
}

function confirmedAppointment(array $overrides = []): Appointment
{
    $appointment = Appointment::factory()->approved()->create([
        'doctor_id' => test()->doctor->id,
        'start_at' => test()->slotStart,
        'end_at' => test()->slotStart->addMinutes(30),
        ...$overrides,
    ]);

    Payment::factory()->create([
        'appointment_id' => $appointment->id,
        'status' => PaymentStatus::Verified,
    ]);

    return $appointment;
}

it('turns patients and guests away from clinic operations', function (): void {
    $appointment = confirmedAppointment();

    $this->postJson(route('api.v1.staff.appointments.store'), phonePayload())->assertUnauthorized();

    $this->actingAsPortal(User::factory()->patient()->create());

    $this->postJson(route('api.v1.staff.appointments.store'), phonePayload())->assertForbidden();
    $this->postJson(route('api.v1.staff.appointments.cancel', $appointment), ['reason' => 'x'])->assertForbidden();
    $this->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 60])
        ->assertForbidden();
    $this->getJson(route('api.v1.staff.patients.index'))->assertForbidden();
    $this->getJson(route('api.v1.staff.payments.index'))->assertForbidden();
    $this->getJson(route('api.v1.staff.schedules.index'))->assertForbidden();
});

it('books a caller as confirmed and paid in cash, opening an account for a new number', function (): void {
    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.appointments.store'), phonePayload())
        ->assertCreated()
        ->assertJsonPath('data.status', AppointmentStatus::Approved->value)
        ->assertJsonPath('data.patient.name', 'أحمد يوسف')
        ->assertJsonPath('data.payment.status', PaymentStatus::Verified->value)
        ->assertJsonPath('data.payment.method', 'cash')
        ->assertJsonPath('data.hold_expires_at', null);

    $patient = User::where('phone', '+970599123456')->sole();
    $appointment = Appointment::query()->sole();

    expect($patient->role)->toBe(UserRole::Patient)
        ->and($patient->email)->toBeNull()
        ->and($appointment->doctor_id)->toBe($this->doctor->id)
        ->and($appointment->patient_id)->toBe($patient->id)
        ->and($appointment->price)->toBe('150.00')
        ->and($appointment->approved_by)->toBe($this->secretary->id)
        ->and($appointment->end_at->equalTo($this->slotStart->addMinutes(30)))->toBeTrue()
        ->and($appointment->payment->verified_by)->toBe($this->secretary->id)
        ->and($appointment->payment->amount)->toBe('150.00')
        ->and($appointment->payment->receipt_number)->toStartWith('NDR-2026-');

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe(AuditAction::AppointmentBookedByPhone)
        ->and($log->user_id)->toBe($this->secretary->id);
});

it('reuses the account of a caller who already has one', function (): void {
    $patient = User::factory()->patient()->create(['name' => 'Existing', 'phone' => '+970599123456']);

    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.staff.appointments.store'), phonePayload())
        ->assertCreated()
        ->assertJsonPath('data.patient.id', $patient->id)
        ->assertJsonPath('data.patient.name', 'Existing');

    expect(User::where('role', UserRole::Patient)->count())->toBe(1);
});

it('refuses a phone number that belongs to staff', function (): void {
    $this->otherDoctor->update(['phone' => '+970599123456']);

    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.appointments.store'), phonePayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('patient_phone');
});

it('answers 409 for a taken slot and leaves no account behind', function (): void {
    confirmedAppointment();

    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.appointments.store'), phonePayload())
        ->assertStatus(409);

    expect(User::where('phone', '+970599123456')->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('keeps a secretary to their own doctor and makes the admin name one', function (): void {
    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.appointments.store'), phonePayload(['doctor_id' => $this->otherDoctor->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.staff.appointments.store'), phonePayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');

    $this->postJson(route('api.v1.staff.appointments.store'), phonePayload(['doctor_id' => $this->doctor->id]))
        ->assertCreated()
        ->assertJsonPath('data.doctor.id', $this->doctor->id);
});

it('refuses a service the doctor does not offer and a time in the past', function (): void {
    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.appointments.store'), phonePayload([
            'service_id' => Service::factory()->create()->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('service_id');

    $this->postJson(route('api.v1.staff.appointments.store'), phonePayload([
        'start_at' => now()->subHour()->toIso8601String(),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_at');
});

it('cancels a paid appointment on the clinic side and flags the refund', function (): void {
    $appointment = confirmedAppointment();

    $this->travelTo($this->slotStart->subHours(2));

    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.appointments.cancel', $appointment), ['reason' => 'الطبيب مسافر'])
        ->assertOk()
        ->assertJsonPath('data.status', AppointmentStatus::Cancelled->value)
        ->assertJsonPath('data.cancellation_reason', 'الطبيب مسافر')
        ->assertJsonPath('data.refund_required', true);

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe(AuditAction::AppointmentCancelledByClinic)
        ->and($log->user_id)->toBe($this->secretary->id)
        ->and($log->changes)->toBe(['reason' => 'الطبيب مسافر', 'refund_required' => true]);
});

it('does not flag a refund when nothing was paid', function (): void {
    $appointment = Appointment::factory()->pendingPayment()->create(['doctor_id' => $this->doctor->id]);

    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.staff.appointments.cancel', $appointment), ['reason' => 'Clinic closed'])
        ->assertOk()
        ->assertJsonPath('data.refund_required', false);
});

it('requires a reason and refuses an appointment already closed', function (): void {
    $appointment = confirmedAppointment();

    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.appointments.cancel', $appointment), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $appointment->update(['status' => AppointmentStatus::Cancelled]);

    $this->postJson(route('api.v1.staff.appointments.cancel', $appointment), ['reason' => 'Again'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('stops staff of another clinic from cancelling or resizing', function (): void {
    $appointment = confirmedAppointment();
    $outsider = User::factory()->secretary($this->otherDoctor)->create();

    foreach ([$this->otherDoctor, $outsider] as $user) {
        $this->actingAsPortal($user);

        $this->postJson(route('api.v1.staff.appointments.cancel', $appointment), ['reason' => 'Nope'])
            ->assertForbidden();
        $this->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 60])
            ->assertForbidden();
    }

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Approved)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('lets the admin cancel in any clinic', function (): void {
    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.staff.appointments.cancel', confirmedAppointment()), ['reason' => 'Platform decision'])
        ->assertOk();
});

it('resizes an appointment and records the change', function (): void {
    $appointment = confirmedAppointment();

    $this->actingAsPortal($this->secretary)
        ->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 60])
        ->assertOk()
        ->assertJsonPath('data.end_at', $this->slotStart->addMinutes(60)->toIso8601String());

    expect($appointment->refresh()->start_at->equalTo($this->slotStart))->toBeTrue()
        ->and(AuditLog::query()->sole()->changes)->toBe(['from_minutes' => 30, 'to_minutes' => 60]);
});

it('answers 409 when the new duration runs into the next appointment', function (): void {
    $appointment = confirmedAppointment();
    confirmedAppointment([
        'start_at' => $this->slotStart->addMinutes(30),
        'end_at' => $this->slotStart->addMinutes(60),
    ]);

    $this->actingAsPortal($this->secretary)
        ->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 45])
        ->assertStatus(409);

    expect($appointment->refresh()->end_at->equalTo($this->slotStart->addMinutes(30)))->toBeTrue();

    $this->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 20])
        ->assertOk();
});

it('ignores a neighbour that was cancelled when resizing', function (): void {
    $appointment = confirmedAppointment();
    confirmedAppointment([
        'start_at' => $this->slotStart->addMinutes(30),
        'end_at' => $this->slotStart->addMinutes(60),
        'status' => AppointmentStatus::Cancelled,
    ]);

    $this->actingAsPortal($this->doctor)
        ->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 60])
        ->assertOk();
});

it('validates the duration and refuses a closed appointment', function (): void {
    $appointment = confirmedAppointment();

    $this->actingAsPortal($this->secretary)
        ->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 2])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration_minutes');

    $appointment->update(['status' => AppointmentStatus::Completed]);

    $this->patchJson(route('api.v1.staff.appointments.duration', $appointment), ['duration_minutes' => 60])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});
