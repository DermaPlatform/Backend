<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\ConsultationStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Sprint 5 — the doctor prices their services, switches new work on and
 * off, closes visits and reads what they earned.
 */
beforeEach(function (): void {
    $this->timezone = config('nadara.clinic_timezone');

    $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00', $this->timezone));

    $this->doctor = User::factory()->doctor()->create();
    $this->secretary = User::factory()->secretary($this->doctor)->create();
    $this->otherDoctor = User::factory()->doctor()->create();
    $this->admin = User::factory()->admin()->create();
    $this->service = Service::factory()->create(['name' => 'Laser']);
});

function visit(array $overrides = []): Appointment
{
    return Appointment::factory()->approved()->create([
        'doctor_id' => test()->doctor->id,
        'service_id' => test()->service->id,
        'start_at' => now()->subHour(),
        'end_at' => now()->subMinutes(30),
        'price' => 100,
        ...$overrides,
    ]);
}

it('keeps everyone but a doctor out of the doctor tools', function (string $who, int $status): void {
    $user = match ($who) {
        'secretary' => $this->secretary,
        'admin' => $this->admin,
        'patient' => User::factory()->patient()->create(),
        default => null,
    };

    if ($user !== null) {
        $this->actingAsPortal($user);
    }

    $this->getJson(route('api.v1.doctor.services.index'))->assertStatus($status);
    $this->getJson(route('api.v1.doctor.preferences.show'))->assertStatus($status);
    $this->getJson(route('api.v1.doctor.income'))->assertStatus($status);
    $this->putJson(route('api.v1.doctor.services.update', $this->service), [
        'price' => 10,
        'duration_minutes' => 30,
    ])->assertStatus($status);
})->with([
    'guest' => ['guest', 401],
    'patient' => ['patient', 403],
    'secretary' => ['secretary', 403],
    'admin' => ['admin', 403],
]);

it('lists the catalog with the doctor\'s own terms', function (): void {
    $unpriced = Service::factory()->create(['name' => 'Peeling']);
    Service::factory()->create(['name' => 'Retired', 'is_active' => false]);

    $this->doctor->offeredServices()->attach($this->service->id, [
        'price' => 150,
        'duration_minutes' => 45,
        'is_active' => true,
    ]);
    $this->otherDoctor->offeredServices()->attach($unpriced->id, [
        'price' => 999,
        'duration_minutes' => 10,
        'is_active' => true,
    ]);

    $this->actingAsPortal($this->doctor)
        ->getJson(route('api.v1.doctor.services.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Laser')
        ->assertJsonPath('data.0.offering.price', '150.00')
        ->assertJsonPath('data.0.offering.duration_minutes', 45)
        ->assertJsonPath('data.1.name', 'Peeling')
        ->assertJsonPath('data.1.offering', null);
});

it('prices a service, which puts the doctor in the catalog for it', function (): void {
    $this->actingAsPortal($this->doctor)
        ->putJson(route('api.v1.doctor.services.update', $this->service), [
            'price' => 120.5,
            'duration_minutes' => 40,
        ])
        ->assertOk()
        ->assertJsonPath('data.offering.price', '120.50')
        ->assertJsonPath('data.offering.duration_minutes', 40)
        ->assertJsonPath('data.offering.is_active', true);

    $this->getJson(route('api.v1.doctors.index', ['service_id' => $this->service->id]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$this->doctor->id]);
});

it('reprices without touching appointments already booked, and can switch a service off', function (): void {
    $this->doctor->offeredServices()->attach($this->service->id, [
        'price' => 100,
        'duration_minutes' => 30,
        'is_active' => true,
    ]);
    $booked = visit(['start_at' => now()->addDay(), 'end_at' => now()->addDay()->addMinutes(30)]);

    $this->actingAsPortal($this->doctor)
        ->putJson(route('api.v1.doctor.services.update', $this->service), [
            'price' => 300,
            'duration_minutes' => 60,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.offering.is_active', false);

    expect($booked->refresh()->price)->toBe('100.00')
        ->and($this->doctor->offeredServices()->count())->toBe(1);

    $this->getJson(route('api.v1.doctors.index', ['service_id' => $this->service->id]))
        ->assertJsonCount(0, 'data');

    // Left out, is_active keeps whatever it was.
    $this->putJson(route('api.v1.doctor.services.update', $this->service), [
        'price' => 310,
        'duration_minutes' => 60,
    ])->assertJsonPath('data.offering.is_active', false);
});

it('validates the terms and refuses a retired service', function (): void {
    $this->actingAsPortal($this->doctor)
        ->putJson(route('api.v1.doctor.services.update', $this->service), [
            'price' => -5,
            'duration_minutes' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price', 'duration_minutes']);

    $this->putJson(route('api.v1.doctor.services.update', Service::factory()->create(['is_active' => false])), [
        'price' => 50,
        'duration_minutes' => 30,
    ])->assertNotFound();
});

it('reads and changes the two switches over new work', function (): void {
    $this->actingAsPortal($this->doctor)
        ->getJson(route('api.v1.doctor.preferences.show'))
        ->assertOk()
        ->assertJsonPath('data.accepts_appointments', true)
        ->assertJsonPath('data.accepts_online_consultations', false);

    $this->putJson(route('api.v1.doctor.preferences.update'), ['accepts_online_consultations' => true])
        ->assertOk()
        ->assertJsonPath('data.accepts_appointments', true)
        ->assertJsonPath('data.accepts_online_consultations', true);

    $this->putJson(route('api.v1.doctor.preferences.update'), ['accepts_appointments' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('accepts_appointments');

    expect($this->otherDoctor->doctorProfile->refresh()->accepts_online_consultations)->toBeFalse();
});

it('completes a visit that has started and records it', function (string $who): void {
    $appointment = visit();

    $this->actingAsPortal($this->{$who})
        ->postJson(route('api.v1.staff.appointments.complete', $appointment))
        ->assertOk()
        ->assertJsonPath('data.status', AppointmentStatus::Completed->value);

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe(AuditAction::AppointmentCompleted)
        ->and($log->user_id)->toBe($this->{$who}->id);
})->with(['doctor', 'secretary', 'admin']);

it('refuses to complete a future, unconfirmed or foreign appointment', function (): void {
    $this->actingAsPortal($this->doctor);

    $this->postJson(route('api.v1.staff.appointments.complete', visit([
        'start_at' => now()->addHour(),
        'end_at' => now()->addHours(2),
    ])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_at');

    $this->postJson(route('api.v1.staff.appointments.complete', visit(['status' => AppointmentStatus::AwaitingReview])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->postJson(route('api.v1.staff.appointments.complete', visit(['doctor_id' => $this->otherDoctor->id])))
        ->assertForbidden();

    expect(AuditLog::query()->count())->toBe(0);
});

it('reports the month\'s income from completed visits and answered consultations', function (): void {
    $peeling = Service::factory()->create(['name' => 'Peeling']);

    visit(['status' => AppointmentStatus::Completed, 'price' => 100]);
    visit(['status' => AppointmentStatus::Completed, 'price' => 150.50, 'start_at' => now()->subDays(3)]);
    visit(['status' => AppointmentStatus::Completed, 'price' => 80, 'service_id' => $peeling->id]);

    // None of these count: not completed, last month, another doctor.
    visit(['price' => 1000]);
    visit(['status' => AppointmentStatus::Completed, 'price' => 1000, 'start_at' => now()->subMonth()]);
    visit(['status' => AppointmentStatus::Completed, 'price' => 1000, 'doctor_id' => $this->otherDoctor->id]);

    $answered = Consultation::factory()->create([
        'doctor_id' => $this->doctor->id,
        'price' => 40,
        'status' => ConsultationStatus::Replied,
        'replied_at' => now()->subDay(),
    ]);
    Payment::factory()->create([
        'appointment_id' => null,
        'consultation_id' => $answered->id,
        'patient_id' => $answered->patient_id,
        'amount' => 40,
        'status' => PaymentStatus::Verified,
    ]);
    // Paid but not answered yet.
    Consultation::factory()->create([
        'doctor_id' => $this->doctor->id,
        'price' => 1000,
        'status' => ConsultationStatus::AwaitingReply,
    ]);

    $this->actingAsPortal($this->doctor)
        ->getJson(route('api.v1.doctor.income'))
        ->assertOk()
        ->assertExactJson(['data' => [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'total' => '370.50',
            'appointments' => [
                'count' => 3,
                'total' => '330.50',
                'by_service' => [
                    ['service_id' => $this->service->id, 'name' => 'Laser', 'count' => 2, 'total' => '250.50'],
                    ['service_id' => $peeling->id, 'name' => 'Peeling', 'count' => 1, 'total' => '80.00'],
                ],
            ],
            'consultations' => ['count' => 1, 'total' => '40.00'],
        ]]);
});

it('does not rewrite past income when the doctor changes a price', function (): void {
    $this->doctor->offeredServices()->attach($this->service->id, [
        'price' => 100,
        'duration_minutes' => 30,
        'is_active' => true,
    ]);
    visit(['status' => AppointmentStatus::Completed, 'price' => 100]);

    $this->actingAsPortal($this->doctor);

    $this->putJson(route('api.v1.doctor.services.update', $this->service), ['price' => 500, 'duration_minutes' => 30])
        ->assertOk();

    $this->getJson(route('api.v1.doctor.income'))->assertJsonPath('data.total', '100.00');
});

it('reports a chosen range and an empty one', function (): void {
    visit(['status' => AppointmentStatus::Completed, 'price' => 70, 'start_at' => now()->subMonth()]);

    $this->actingAsPortal($this->doctor)
        ->getJson(route('api.v1.doctor.income', ['from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertJsonPath('data.from', '2026-08-01')
        ->assertJsonPath('data.total', '70.00');

    $this->getJson(route('api.v1.doctor.income', ['from' => '2026-07-01', 'to' => '2026-07-31']))
        ->assertOk()
        ->assertJsonPath('data.total', '0.00')
        ->assertJsonPath('data.appointments.by_service', [])
        ->assertJsonPath('data.consultations.count', 0);

    $this->getJson(route('api.v1.doctor.income', ['from' => '2026-08-31', 'to' => '2026-08-01']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});
