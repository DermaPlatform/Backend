<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\MedicalNote;
use App\Models\User;

/**
 * US-020 — the doctor's clinical notes and a patient's visit history.
 */
beforeEach(function (): void {
    $this->doctor = User::factory()->doctor()->create();
    $this->secretary = User::factory()->secretary($this->doctor)->create();
    $this->otherDoctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patient()->create();

    $this->appointment = Appointment::factory()->approved()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
    ]);
});

it('writes a note for a visit and revises it in place', function (): void {
    $this->actingAsPortal($this->doctor);

    $this->getJson(route('api.v1.doctor.medical-notes.show', $this->appointment))
        ->assertOk()
        ->assertJsonPath('data', null);

    $this->putJson(route('api.v1.doctor.medical-notes.update', $this->appointment), ['notes' => 'أكزيما خفيفة'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'أكزيما خفيفة')
        ->assertJsonPath('data.appointment_id', $this->appointment->id);

    $this->putJson(route('api.v1.doctor.medical-notes.update', $this->appointment), ['notes' => 'تحسّن واضح'])
        ->assertOk();

    $note = MedicalNote::query()->sole();

    expect($note->notes)->toBe('تحسّن واضح')
        ->and($note->patient_id)->toBe($this->patient->id)
        ->and($note->doctor_id)->toBe($this->doctor->id);

    $this->getJson(route('api.v1.doctor.medical-notes.show', $this->appointment))
        ->assertJsonPath('data.notes', 'تحسّن واضح');
});

it('keeps another doctor out of the note', function (): void {
    MedicalNote::factory()->create(['appointment_id' => $this->appointment->id]);

    $this->actingAsPortal($this->otherDoctor);

    $this->getJson(route('api.v1.doctor.medical-notes.show', $this->appointment))->assertForbidden();
    $this->putJson(route('api.v1.doctor.medical-notes.update', $this->appointment), ['notes' => 'Mine now'])
        ->assertForbidden();
    $this->getJson(route('api.v1.doctor.patients.history', $this->patient))->assertNotFound();
});

it('keeps the secretary, the admin and the patient out of the note', function (string $who): void {
    MedicalNote::factory()->create(['appointment_id' => $this->appointment->id]);

    $user = match ($who) {
        'secretary' => $this->secretary,
        'patient' => $this->patient,
        'admin' => User::factory()->admin()->create(),
    };

    $this->actingAsPortal($user);

    $this->getJson(route('api.v1.doctor.medical-notes.show', $this->appointment))->assertForbidden();
    $this->putJson(route('api.v1.doctor.medical-notes.update', $this->appointment), ['notes' => 'x'])
        ->assertForbidden();
    $this->getJson(route('api.v1.doctor.patients.history', $this->patient))->assertForbidden();
})->with(['secretary', 'patient', 'admin']);

it('requires the text and a visit that was confirmed', function (): void {
    $this->actingAsPortal($this->doctor);

    $this->putJson(route('api.v1.doctor.medical-notes.update', $this->appointment), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notes');

    $unpaid = Appointment::factory()->pendingPayment()->create(['doctor_id' => $this->doctor->id]);

    $this->putJson(route('api.v1.doctor.medical-notes.update', $unpaid), ['notes' => 'Too early'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('shows a patient\'s visits to this doctor only, newest first, with their notes', function (): void {
    $earlier = Appointment::factory()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'status' => AppointmentStatus::Completed,
        'start_at' => now()->subMonth(),
        'end_at' => now()->subMonth()->addMinutes(30),
    ]);
    MedicalNote::factory()->create(['appointment_id' => $earlier->id, 'notes' => 'First visit']);

    // Not part of the history: cancelled, and a visit to someone else.
    Appointment::factory()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
        'status' => AppointmentStatus::Cancelled,
    ]);
    $elsewhere = Appointment::factory()->approved()->create([
        'doctor_id' => $this->otherDoctor->id,
        'patient_id' => $this->patient->id,
    ]);
    MedicalNote::factory()->create(['appointment_id' => $elsewhere->id, 'notes' => 'Private to the other doctor']);

    $this->actingAsPortal($this->doctor)
        ->getJson(route('api.v1.doctor.patients.history', $this->patient))
        ->assertOk()
        ->assertJsonPath('data.patient.id', $this->patient->id)
        ->assertJsonPath('data.patient.gender', $this->patient->gender->value)
        ->assertJsonPath('data.visits.*.appointment_id', [$this->appointment->id, $earlier->id])
        ->assertJsonPath('data.visits.0.note', null)
        ->assertJsonPath('data.visits.1.note.notes', 'First visit')
        ->assertDontSee('Private to the other doctor');
});

it('answers 404 for someone who is not this doctor\'s patient', function (): void {
    $this->actingAsPortal($this->doctor);

    $this->getJson(route('api.v1.doctor.patients.history', User::factory()->patient()->create()))->assertNotFound();
    $this->getJson(route('api.v1.doctor.patients.history', $this->otherDoctor))->assertNotFound();
});
