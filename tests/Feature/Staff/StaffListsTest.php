<?php

use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;

/**
 * US-016 and US-014 — the clinic's patient list and its payments queue.
 */
beforeEach(function (): void {
    $this->doctor = User::factory()->doctor()->create();
    $this->secretary = User::factory()->secretary($this->doctor)->create();
    $this->otherDoctor = User::factory()->doctor()->create();
    $this->admin = User::factory()->admin()->create();

    $this->mine = User::factory()->patient()->create(['name' => 'Amal', 'phone' => '+970599000001']);
    $this->theirs = User::factory()->patient()->create(['name' => 'Basel', 'phone' => '+970599000002']);
    $this->nobodys = User::factory()->patient()->create(['name' => 'Camil']);

    $this->myAppointment = Appointment::factory()->approved()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->mine->id,
    ]);
    $this->theirAppointment = Appointment::factory()->approved()->create([
        'doctor_id' => $this->otherDoctor->id,
        'patient_id' => $this->theirs->id,
    ]);
});

it('lists only the patients of the caller\'s clinic', function (string $who): void {
    Appointment::factory()->create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->mine->id]);

    $this->actingAsPortal($this->{$who})
        ->getJson(route('api.v1.staff.patients.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$this->mine->id])
        ->assertJsonPath('data.0.appointments_count', 2)
        ->assertJsonPath('data.0.phone', '+970599000001')
        ->assertJsonMissingPath('data.0.birth_date')
        ->assertJsonMissingPath('data.0.gender')
        ->assertJsonMissingPath('data.0.age');
})->with(['doctor', 'secretary']);

it('shows the admin every patient and lets them narrow to one clinic', function (): void {
    $this->actingAsPortal($this->admin)
        ->getJson(route('api.v1.staff.patients.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$this->mine->id, $this->theirs->id, $this->nobodys->id]);

    $this->getJson(route('api.v1.staff.patients.index', ['doctor_id' => $this->otherDoctor->id]))
        ->assertJsonPath('data.*.id', [$this->theirs->id]);
});

it('searches patients by name or phone', function (): void {
    $this->actingAsPortal($this->admin);

    $this->getJson(route('api.v1.staff.patients.index', ['q' => 'ase']))
        ->assertJsonPath('data.*.id', [$this->theirs->id]);

    $this->getJson(route('api.v1.staff.patients.index', ['q' => '000001']))
        ->assertJsonPath('data.*.id', [$this->mine->id]);
});

it('does not let clinic staff name another doctor', function (): void {
    $this->actingAsPortal($this->secretary)
        ->getJson(route('api.v1.staff.patients.index', ['doctor_id' => $this->otherDoctor->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');

    $this->getJson(route('api.v1.staff.payments.index', ['doctor_id' => $this->otherDoctor->id]))
        ->assertUnprocessable();
});

it('lists the clinic\'s payments, consultations included, newest first', function (): void {
    $appointmentPayment = Payment::factory()->create([
        'appointment_id' => $this->myAppointment->id,
        'status' => PaymentStatus::Submitted,
        'receipt_number' => 'NDR-2026-000001',
        'created_at' => now()->subDay(),
    ]);

    $consultation = Consultation::factory()->create(['doctor_id' => $this->doctor->id]);
    $consultationPayment = Payment::factory()->create([
        'appointment_id' => null,
        'consultation_id' => $consultation->id,
        'patient_id' => $consultation->patient_id,
        'amount' => 40,
        'status' => PaymentStatus::Verified,
    ]);

    Payment::factory()->create(['appointment_id' => $this->theirAppointment->id]);

    $this->actingAsPortal($this->secretary)
        ->getJson(route('api.v1.staff.payments.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$consultationPayment->id, $appointmentPayment->id])
        ->assertJsonPath('data.0.consultation_id', $consultation->id)
        ->assertJsonMissingPath('data.0.appointment')
        ->assertJsonPath('data.1.patient.name', 'Amal')
        ->assertJsonPath('data.1.appointment.id', $this->myAppointment->id)
        ->assertJsonMissingPath('data.1.patient.birth_date');

    $this->getJson(route('api.v1.staff.payments.index', ['status' => 'submitted']))
        ->assertJsonPath('data.*.id', [$appointmentPayment->id]);

    $this->getJson(route('api.v1.staff.payments.index', ['q' => '000001']))
        ->assertJsonPath('data.*.id', [$appointmentPayment->id]);

    $this->getJson(route('api.v1.staff.payments.index', ['q' => 'Amal']))
        ->assertJsonPath('data.*.id', [$appointmentPayment->id]);

    $this->getJson(route('api.v1.staff.payments.index', ['from' => now(config('nadara.clinic_timezone'))->toDateString()]))
        ->assertJsonPath('data.*.id', [$consultationPayment->id]);

    $this->getJson(route('api.v1.staff.payments.index', ['status' => 'nonsense']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('shows the admin payments across clinics and lets them narrow to one', function (): void {
    $mine = Payment::factory()->create(['appointment_id' => $this->myAppointment->id]);
    $theirs = Payment::factory()->create(['appointment_id' => $this->theirAppointment->id]);

    $this->actingAsPortal($this->admin)
        ->getJson(route('api.v1.staff.payments.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson(route('api.v1.staff.payments.index', ['doctor_id' => $this->otherDoctor->id]))
        ->assertJsonPath('data.*.id', [$theirs->id]);

    expect($mine->id)->not->toBe($theirs->id);
});
