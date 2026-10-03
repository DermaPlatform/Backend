<?php

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\ConsultationStatus;
use App\Enums\NotificationKind;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\MedicalNote;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ClinicUpdate;
use Illuminate\Support\Facades\DB;

/**
 * US-023 — no list runs a query per row.
 *
 * Each list is requested twice, once over a few records and once over three
 * times as many. If the number of queries grows with the number of rows,
 * something on that page is loading a relation one row at a time. The count
 * itself is not asserted, only that it stays put.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->doctor = User::factory()->doctor()->create();
    $this->secretary = User::factory()->secretary($this->doctor)->create();
    $this->patient = User::factory()->patient()->create();
    $this->service = Service::factory()->create();

    $this->consultation = Consultation::factory()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'status' => ConsultationStatus::Replied,
    ]);
});

/**
 * One more of everything a list can show: a doctor with a secretary and a
 * priced service and work of their own, a patient with a completed, noted
 * and paid visit to the shared doctor, a consultation with a paid receipt, a message from each
 * side, an audit entry and a notification.
 */
function addOneOfEverything(): void
{
    $test = test();

    $doctor = User::factory()->doctor()->create();
    User::factory()->secretary($doctor)->create();

    $service = Service::factory()->create();
    foreach ([$doctor, $test->doctor] as $offering) {
        $offering->offeredServices()->attach($service->id, [
            'price' => 100,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
    }

    $patient = User::factory()->patient()->create();

    foreach ([$patient, $test->patient] as $visitor) {
        $appointment = Appointment::factory()->create([
            'doctor_id' => $test->doctor->id,
            'patient_id' => $visitor->id,
            'service_id' => $service->id,
            'status' => AppointmentStatus::Completed,
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addMinutes(30),
        ]);
        Payment::factory()->create(['appointment_id' => $appointment->id, 'status' => PaymentStatus::Verified]);
        MedicalNote::factory()->create(['appointment_id' => $appointment->id]);
    }

    $consultation = Consultation::factory()->create([
        'doctor_id' => $test->doctor->id,
        'patient_id' => $patient->id,
        'status' => ConsultationStatus::Replied,
        'replied_at' => now()->subDay(),
    ]);
    Payment::factory()->create([
        'appointment_id' => null,
        'consultation_id' => $consultation->id,
        'patient_id' => $patient->id,
        'amount' => 40,
        'status' => PaymentStatus::Verified,
    ]);

    foreach ([$test->patient, $test->doctor] as $sender) {
        $test->consultation->messages()->create(['sender_id' => $sender->id, 'message' => 'A line in the thread.']);
    }

    // And work for the new doctor too, so the admin's lists span clinics.
    $elsewhere = Appointment::factory()->approved()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
    ]);
    Payment::factory()->create(['appointment_id' => $elsewhere->id, 'status' => PaymentStatus::Submitted]);
    Consultation::factory()->create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id]);

    AuditLog::record($test->secretary, AuditAction::PaymentVerified, $appointment->payment);
    AuditLog::record($test->admin, AuditAction::AccountCreated, $doctor);

    $test->patient->notify(new ClinicUpdate(NotificationKind::AppointmentConfirmed, ['appointment_id' => $appointment->id]));
}

function queriesFor(?User $user, string $url): int
{
    $test = test();

    app('auth')->forgetGuards();
    $test->flushHeaders();

    if ($user !== null) {
        $test->actingAsPortal($user);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->getJson($url)->assertOk();

    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

it('runs the same number of queries however many rows there are', function (string $actor, string $route, array $parameters = []): void {
    $user = $actor === 'guest' ? null : $this->{$actor};

    $resolved = array_map(fn (string $property): int => $this->{$property}->id, $parameters);

    $url = route('api.v1.'.$route, [...$resolved, 'per_page' => 100]);

    for ($i = 0; $i < 3; $i++) {
        addOneOfEverything();
    }
    $few = queriesFor($user, $url);

    for ($i = 0; $i < 6; $i++) {
        addOneOfEverything();
    }
    $many = queriesFor($user, $url);

    expect($many)->toBe($few, "{$route} ran {$few} queries over a few rows and {$many} over many");
})->with([
    'catalog categories' => ['guest', 'categories.index'],
    'catalog services' => ['guest', 'services.index'],
    'doctors directory' => ['guest', 'doctors.index'],
    'appointments, as the admin' => ['admin', 'appointments.index'],
    'appointments, as the doctor' => ['doctor', 'appointments.index'],
    'appointments, as the secretary' => ['secretary', 'appointments.index'],
    'appointments, as the patient' => ['patient', 'appointments.index'],
    'consultations, as the admin' => ['admin', 'consultations.index'],
    'consultations, as the doctor' => ['doctor', 'consultations.index'],
    'a consultation thread' => ['doctor', 'consultations.show', ['consultation' => 'consultation']],
    'clinic patients' => ['secretary', 'staff.patients.index'],
    'all patients, as the admin' => ['admin', 'staff.patients.index'],
    'clinic payments' => ['secretary', 'staff.payments.index'],
    'all payments, as the admin' => ['admin', 'staff.payments.index'],
    'clinic shifts' => ['doctor', 'staff.schedules.index'],
    'doctor services' => ['doctor', 'doctor.services.index'],
    'doctor income' => ['doctor', 'doctor.income'],
    'patient history' => ['doctor', 'doctor.patients.history', ['patient' => 'patient']],
    'notifications' => ['patient', 'notifications.index'],
    'admin categories' => ['admin', 'admin.categories.index'],
    'admin services' => ['admin', 'admin.services.index'],
    'admin doctors' => ['admin', 'admin.doctors.index'],
    'admin subscriptions' => ['admin', 'admin.subscriptions.index'],
    'admin audit log' => ['admin', 'admin.audit-logs.index'],
]);
