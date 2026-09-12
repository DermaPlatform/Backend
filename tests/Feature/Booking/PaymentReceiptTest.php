<?php

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * B5 — US-013, uploading and reviewing a payment receipt.
 */
beforeEach(function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));

    $this->patient = User::factory()->patient()->create();
    $this->doctor = User::factory()->doctor()->create();
    $this->admin = User::factory()->admin()->create();

    $this->appointment = Appointment::factory()->pendingPayment()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'price' => 150.00,
    ]);
});

function receiptFile(): UploadedFile
{
    return UploadedFile::fake()->image('receipt.jpg');
}

it('accepts a receipt and moves the appointment into review', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), [
            'receipt' => receiptFile(),
            'method' => 'bank_transfer',
            'reference' => 'TRX-99',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', AppointmentStatus::AwaitingReview->value);

    $payment = Payment::firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::Submitted)
        ->and($payment->amount)->toBe('150.00')
        ->and($this->appointment->refresh()->hold_expires_at)->toBeNull();
});

it('stores the receipt on the private disk, never the public one', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), [
            'receipt' => receiptFile(),
        ])
        ->assertCreated();

    $path = Payment::firstOrFail()->receipt_path;

    Storage::disk(config('nadara.uploads.private_disk'))->assertExists($path);
    expect($path)->toStartWith(config('nadara.uploads.receipt_directory').'/');
});

it('never exposes the stored receipt path in the response', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), [
            'receipt' => receiptFile(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.payment.has_receipt', true)
        ->assertJsonMissingPath('data.payment.receipt_path');
});

it('issues a sequential receipt number for the year', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()])
        ->assertCreated();

    $second = Appointment::factory()->pendingPayment()->create(['patient_id' => $this->patient->id]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $second), ['receipt' => receiptFile()])
        ->assertCreated();

    expect(Payment::orderBy('id')->pluck('receipt_number')->all())->toBe([
        'NDR-'.now()->year.'-000001',
        'NDR-'.now()->year.'-000002',
    ]);
});

it('rejects a file type that is not a receipt', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), [
            'receipt' => UploadedFile::fake()->create('malware.exe', 12),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('receipt');
});

it('rejects a receipt larger than the limit', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), [
            'receipt' => UploadedFile::fake()->create('huge.pdf', config('nadara.uploads.receipt_max_kb') + 1),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('receipt');
});

it('refuses a receipt for someone else appointment', function (): void {
    $this->actingAs(User::factory()->patient()->create())
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()])
        ->assertForbidden();
});

it('refuses a second receipt once the appointment left pending payment', function (): void {
    $this->appointment->update(['status' => AppointmentStatus::Approved]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()])
        ->assertStatus(422);
});

it('approves the appointment when staff verify the payment', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()]);

    $payment = Payment::firstOrFail();

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.payments.verify', $payment))
        ->assertOk()
        ->assertJsonPath('data.status', PaymentStatus::Verified->value);

    expect($this->appointment->refresh()->status)->toBe(AppointmentStatus::Approved)
        ->and($this->appointment->approved_by)->toBe($this->admin->id);
});

it('returns the appointment to pending payment when staff reject the receipt', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()]);

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.payments.reject', Payment::firstOrFail()), ['reason' => 'Slip is unreadable.'])
        ->assertOk();

    $this->appointment->refresh();

    expect($this->appointment->status)->toBe(AppointmentStatus::PendingPayment)
        ->and($this->appointment->hold_expires_at)->not->toBeNull();
});

it('requires a reason when rejecting', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()]);

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.payments.reject', Payment::firstOrFail()), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('lets the doctor of the appointment review the payment', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()]);

    $this->actingAs($this->doctor)
        ->postJson(route('api.v1.payments.verify', Payment::firstOrFail()))
        ->assertOk();
});

it('lets the secretary of that doctor review the payment', function (): void {
    $secretary = User::factory()->secretary($this->doctor)->create();

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()]);

    $this->actingAs($secretary)
        ->postJson(route('api.v1.payments.verify', Payment::firstOrFail()))
        ->assertOk();
});

it('refuses a secretary who works for a different doctor', function (): void {
    $secretary = User::factory()->secretary(User::factory()->doctor()->create())->create();

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()]);

    $this->actingAs($secretary)
        ->postJson(route('api.v1.payments.verify', Payment::firstOrFail()))
        ->assertForbidden();
});

it('refuses a patient trying to verify their own payment', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.appointments.receipt.store', $this->appointment), ['receipt' => receiptFile()]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.payments.verify', Payment::firstOrFail()))
        ->assertForbidden();
});
