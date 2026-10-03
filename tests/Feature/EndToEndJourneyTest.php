<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * US-024 — the whole platform in one run, through the HTTP API alone.
 *
 * Nothing here reaches into a model to set things up beyond the first admin
 * and one catalog service, which is what a fresh install has. Every other
 * record is made the way a real user makes it, with tokens from the real
 * sign in doors, so a break anywhere along the chain shows up here even when
 * each piece still passes its own tests.
 */
it('carries a clinic and a patient from first sign in to income', function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));

    $timezone = config('nadara.clinic_timezone');
    // A Monday morning.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00', $timezone));

    User::factory()->admin()->create(['email' => 'admin@nadara.test']);
    $service = Service::factory()->create(['name' => 'Laser']);

    /** Send a request as whoever holds this token. */
    $as = function (?string $token, string $method, string $uri, array $data = []): TestResponse {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->json($method, $uri, $data);
    };

    $login = fn (string $route, string $email, string $password): string => $as(null, 'POST', route($route), [
        'email' => $email,
        'password' => $password,
    ])->assertOk()->json('data.token');

    // 1. The admin opens the clinic: a doctor on trial and their secretary.
    $admin = $login('api.v1.admin.auth.login', 'admin@nadara.test', 'password');

    $doctorId = $as($admin, 'POST', route('api.v1.admin.doctors.store'), [
        'name' => 'د. ليلى',
        'email' => 'layla@nadara.test',
        'phone' => '+970599000001',
        'password' => 'clinic-password',
        'specialty' => 'Dermatology',
    ])->assertCreated()->assertJsonPath('data.subscription.status', 'trial')->json('data.id');

    $as($admin, 'POST', route('api.v1.admin.doctors.secretaries.store', $doctorId), [
        'name' => 'سناء',
        'email' => 'sana@nadara.test',
        'phone' => '+970599000002',
        'password' => 'clinic-password',
    ])->assertCreated();

    // 2. The doctor sets the clinic up: a price, a shift, online consultations on.
    $doctor = $login('api.v1.clinic.auth.login', 'layla@nadara.test', 'clinic-password');

    // Not bookable yet: nothing priced.
    $as(null, 'GET', route('api.v1.doctors.index', ['service_id' => $service->id]))->assertJsonCount(0, 'data');

    $as($doctor, 'PUT', route('api.v1.doctor.services.update', $service), [
        'price' => 150,
        'duration_minutes' => 30,
    ])->assertOk();
    $as($doctor, 'POST', route('api.v1.staff.schedules.store'), [
        'weekday' => 2,
        'starts_at' => '09:00',
        'ends_at' => '12:00',
        'slot_minutes' => 30,
    ])->assertCreated();
    $as($doctor, 'PUT', route('api.v1.doctor.preferences.update'), [
        'accepts_online_consultations' => true,
    ])->assertOk();

    // 3. A visitor finds the doctor and a free slot, then registers.
    $as(null, 'GET', route('api.v1.doctors.index', ['service_id' => $service->id]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $doctorId)
        ->assertJsonPath('data.0.services.0.price', '150.00');

    // Tuesday.
    $slots = $as(null, 'GET', route('api.v1.doctors.availability', ['doctor' => $doctorId, 'date' => '2026-09-15']))
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->json('data');
    $slot = collect($slots)->firstWhere('available', true)['start_at'];

    $patient = $as(null, 'POST', route('api.v1.auth.register'), [
        'name' => 'سارة',
        'email' => 'sara@example.test',
        'phone' => '+970599000003',
        'birth_date' => '1995-04-12',
        'gender' => 'female',
        'password' => 'patient-password',
        'password_confirmation' => 'patient-password',
    ])->assertCreated()->json('data.token');

    // 4. The patient books and pays by uploading a receipt.
    $appointmentId = $as($patient, 'POST', route('api.v1.appointments.store'), [
        'doctor_id' => $doctorId,
        'service_id' => $service->id,
        'start_at' => $slot,
    ])->assertCreated()->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.price', '150.00')->json('data.id');

    // The slot is gone for everyone else.
    $as(null, 'GET', route('api.v1.doctors.availability', ['doctor' => $doctorId, 'date' => '2026-09-15']))
        ->assertJsonPath('data.0.available', false);

    $as($patient, 'POST', route('api.v1.appointments.receipt.store', $appointmentId), [
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        'method' => 'bank_transfer',
    ])->assertCreated();

    // 5. The secretary reviews the receipt, which confirms the appointment on its own.
    $secretary = $login('api.v1.clinic.auth.login', 'sana@nadara.test', 'clinic-password');

    $paymentId = $as($secretary, 'GET', route('api.v1.staff.payments.index', ['status' => 'submitted']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.patient.name', 'سارة')
        ->json('data.0.id');

    $as($secretary, 'GET', route('api.v1.appointments.receipt.show', $appointmentId))->assertOk();
    $as($secretary, 'POST', route('api.v1.payments.verify', $paymentId))->assertOk();

    $as($patient, 'GET', route('api.v1.appointments.show', $appointmentId))
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.payment.status', 'verified');
    $as($patient, 'GET', route('api.v1.notifications.index'))
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('data.0.kind', 'appointment.confirmed');

    // 6. The visit: it is on the doctor's day, gets completed, and gets a note.
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:40', $timezone));

    $as($doctor, 'GET', route('api.v1.appointments.index', ['period' => 'today', 'status' => 'approved']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $appointmentId);

    $as($secretary, 'POST', route('api.v1.staff.appointments.complete', $appointmentId))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $as($doctor, 'PUT', route('api.v1.doctor.medical-notes.update', $appointmentId), [
        'notes' => 'Session one done. Review in four weeks.',
    ])->assertOk();

    // The secretary runs the calendar and the money, and stops at the note.
    $as($secretary, 'GET', route('api.v1.doctor.medical-notes.show', $appointmentId))->assertForbidden();
    $as($secretary, 'GET', route('api.v1.staff.patients.index'))
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.birth_date');

    // 7. The same patient opens an online consultation, pays, and is answered.
    $consultationId = $as($patient, 'POST', route('api.v1.consultations.store'), [
        'doctor_id' => $doctorId,
        'service_id' => $service->id,
        'symptoms' => 'Some redness around the treated area since yesterday evening.',
    ])->assertCreated()->json('data.id');

    $as($patient, 'POST', route('api.v1.consultations.receipt.store', $consultationId), [
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
    ])->assertCreated();

    // Not open to the doctor until the money is confirmed, and never to the secretary.
    $as($secretary, 'GET', route('api.v1.consultations.show', $consultationId))->assertForbidden();

    $consultationPaymentId = $as($secretary, 'GET', route('api.v1.staff.payments.index', ['status' => 'submitted']))
        ->assertJsonCount(1, 'data')
        ->json('data.0.id');
    $as($secretary, 'POST', route('api.v1.payments.verify', $consultationPaymentId))->assertOk();

    $this->travel(20)->minutes();

    $as($doctor, 'POST', route('api.v1.consultations.messages.store', $consultationId), [
        'message' => 'That is expected for a day or two. Keep it moisturised.',
    ])->assertCreated();

    $as($patient, 'GET', route('api.v1.consultations.show', $consultationId))
        ->assertOk()
        ->assertJsonPath('data.status', 'replied')
        ->assertJsonCount(1, 'data.messages');
    $as($patient, 'GET', route('api.v1.notifications.index'))
        ->assertJsonPath('unread_count', 3)
        ->assertJsonPath('data.0.kind', 'consultation.replied');

    // 8. The doctor's month: one visit and one consultation, at the prices they were booked at.
    $as($doctor, 'GET', route('api.v1.doctor.income'))
        ->assertOk()
        ->assertJsonPath('data.total', '300.00')
        ->assertJsonPath('data.appointments.count', 1)
        ->assertJsonPath('data.consultations.count', 1);

    $as($doctor, 'GET', route('api.v1.doctor.patients.history', User::where('email', 'sara@example.test')->value('id')))
        ->assertOk()
        ->assertJsonPath('data.visits.0.note.notes', 'Session one done. Review in four weeks.');

    // 9. The admin sees the whole trail and extends the subscription once the doctor pays.
    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe([
        AuditAction::AccountCreated,
        AuditAction::AccountCreated,
        AuditAction::PaymentVerified,
        AuditAction::AppointmentCompleted,
        AuditAction::PaymentVerified,
    ]);

    $as($admin, 'GET', route('api.v1.admin.audit-logs.index'))->assertOk()->assertJsonCount(5, 'data');

    $as($admin, 'POST', route('api.v1.admin.doctors.extend-subscription', $doctorId), [
        'ends_at' => '2026-12-31',
    ])->assertOk()->assertJsonPath('data.subscription.status', 'active');

    // And the clinic's doors hold: a patient token opens nothing on the clinic side.
    $as($patient, 'GET', route('api.v1.staff.payments.index'))->assertForbidden();
    $as($patient, 'GET', route('api.v1.admin.audit-logs.index'))->assertForbidden();
});
