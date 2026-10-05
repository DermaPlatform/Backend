<?php

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Hardening headers on API responses, and on the private files above all.
 */
it('sends hardening headers on every api response', function (): void {
    $this->getJson(route('api.v1.services.index'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox");
});

it('keeps them on errors too', function (): void {
    $this->getJson(route('api.v1.auth.me'))
        ->assertUnauthorized()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('serves a private file sandboxed and uncached', function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));
    Storage::disk(config('nadara.uploads.private_disk'))->put('receipts/slip.jpg', 'image');

    $patient = User::factory()->patient()->create();
    $appointment = Appointment::factory()->awaitingReview()->create(['patient_id' => $patient->id]);
    $payment = Payment::factory()->create(['appointment_id' => $appointment->id, 'receipt_path' => 'receipts/slip.jpg']);

    $response = $this->actingAsPortal($patient)
        ->get(route('api.v1.payments.receipt.show', $payment))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; sandbox");

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
