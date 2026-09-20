<?php

use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Reading back what was uploaded. Staff cannot confirm a receipt they cannot
 * see, and a doctor cannot answer a consultation whose photographs are
 * unreachable, so these routes are part of the flow rather than an extra.
 */
beforeEach(function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));

    $this->patient = User::factory()->patient()->create();
    $this->doctor = User::factory()->doctor()->create();
    $this->admin = User::factory()->admin()->create();

    $this->appointment = Appointment::factory()->awaitingReview()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    /*
     * Built directly rather than by calling the upload endpoint. Going through
     * it would leave actingAs set for the rest of each test, and the check
     * that an anonymous caller is refused would then silently pass as an
     * authenticated one.
     */
    Storage::disk(config('nadara.uploads.private_disk'))
        ->put('receipts/receipt.jpg', UploadedFile::fake()->image('receipt.jpg')->getContent());

    $this->payment = Payment::factory()->create([
        'appointment_id' => $this->appointment->id,
        'patient_id' => $this->patient->id,
        'receipt_path' => 'receipts/receipt.jpg',
    ]);
});

it('lets the patient read back their own receipt', function (): void {
    $this->actingAsPortal($this->patient)
        ->get(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertOk();
});

it('lets the reviewing staff open the receipt they must confirm', function (): void {
    $this->actingAsPortal($this->admin)
        ->get(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertOk();

    $this->actingAsPortal($this->doctor)
        ->get(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertOk();
});

it('lets the secretary of that doctor open the receipt', function (): void {
    $this->actingAsPortal(User::factory()->secretary($this->doctor)->create())
        ->get(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertOk();
});

it('refuses a receipt to an unrelated patient', function (): void {
    $this->actingAsPortal(User::factory()->patient()->create())
        ->get(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertForbidden();
});

it('refuses a receipt to an unrelated doctor', function (): void {
    $this->actingAsPortal(User::factory()->doctor()->create())
        ->get(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertForbidden();
});

it('requires authentication to read a receipt', function (): void {
    $this->getJson(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertUnauthorized();
});

it('answers 404 when no receipt has been uploaded', function (): void {
    $bare = Appointment::factory()->pendingPayment()->create(['patient_id' => $this->patient->id]);

    $this->actingAsPortal($this->patient)
        ->getJson(route('api.v1.appointments.receipt.show', $bare))
        ->assertNotFound();
});

it('answers 404 when the row points at a file that is gone', function (): void {
    Storage::disk(config('nadara.uploads.private_disk'))->delete($this->payment->receipt_path);

    $this->actingAsPortal($this->patient)
        ->getJson(route('api.v1.appointments.receipt.show', $this->appointment))
        ->assertNotFound();
});

it('lets the doctor open the photographs on their consultation', function (): void {
    $consultation = Consultation::factory()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    Storage::disk(config('nadara.uploads.private_disk'))->put('consultations/rash.jpg', 'x');

    $attachment = Attachment::factory()->create([
        'attachable_type' => Consultation::class,
        'attachable_id' => $consultation->id,
        'path' => 'consultations/rash.jpg',
    ]);

    $this->actingAsPortal($this->doctor)
        ->get(route('api.v1.consultations.attachments.show', [$consultation, $attachment]))
        ->assertOk();
});

it('keeps a clinical photograph away from the secretary', function (): void {
    $consultation = Consultation::factory()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $attachment = Attachment::factory()->create([
        'attachable_type' => Consultation::class,
        'attachable_id' => $consultation->id,
    ]);

    $this->actingAsPortal(User::factory()->secretary($this->doctor)->create())
        ->getJson(route('api.v1.consultations.attachments.show', [$consultation, $attachment]))
        ->assertForbidden();
});

/**
 * Pairing someone else's attachment with a consultation you do own must not
 * work, or the policy check is decorative.
 */
it('refuses an attachment that belongs to a different consultation', function (): void {
    $mine = Consultation::factory()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $theirs = Consultation::factory()->create();

    $attachment = Attachment::factory()->create([
        'attachable_type' => Consultation::class,
        'attachable_id' => $theirs->id,
    ]);

    $this->actingAsPortal($this->patient)
        ->getJson(route('api.v1.consultations.attachments.show', [$mine, $attachment]))
        ->assertNotFound();
});
