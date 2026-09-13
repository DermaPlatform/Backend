<?php

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * B6 — US-012, online consultations.
 */
beforeEach(function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));

    $this->patient = User::factory()->patient()->create();
    $this->doctor = User::factory()->doctor()->create();
    $this->admin = User::factory()->admin()->create();
    $this->service = Service::factory()->create();

    $this->doctor->doctorProfile->update(['accepts_online_consultations' => true]);

    $this->doctor->offeredServices()->attach($this->service->id, [
        'price' => 80.00,
        'duration_minutes' => 20,
        'is_active' => true,
    ]);

    $this->symptoms = 'A red itchy patch on my left forearm that has been spreading for two weeks.';
});

function consultationPayload(array $overrides = []): array
{
    return [
        'doctor_id' => test()->doctor->id,
        'service_id' => test()->service->id,
        'symptoms' => test()->symptoms,
        ...$overrides,
    ];
}

it('opens a consultation pending payment', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.store'), consultationPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', ConsultationStatus::PendingPayment->value);

    $consultation = Consultation::firstOrFail();

    expect($consultation->price)->toBe('80.00')
        ->and($consultation->submitted_at)->not->toBeNull();
});

it('stores attached photographs on the private disk', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.store'), consultationPayload([
            'photos' => [
                UploadedFile::fake()->image('rash-1.jpg'),
                UploadedFile::fake()->image('rash-2.jpg'),
            ],
        ]))
        ->assertCreated()
        ->assertJsonCount(2, 'data.attachments');

    $attachments = Consultation::firstOrFail()->attachments;

    expect($attachments)->toHaveCount(2);

    foreach ($attachments as $attachment) {
        Storage::disk(config('nadara.uploads.private_disk'))->assertExists($attachment->path);
    }
});

it('never exposes the stored path of a clinical photograph', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.store'), consultationPayload([
            'photos' => [UploadedFile::fake()->image('rash.jpg')],
        ]))
        ->assertCreated()
        ->assertJsonMissingPath('data.attachments.0.path');
});

it('refuses a doctor who does not take online consultations', function (): void {
    $this->doctor->doctorProfile->update(['accepts_online_consultations' => false]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.store'), consultationPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('doctor_id');
});

it('requires a description long enough to act on', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.store'), consultationPayload(['symptoms' => 'itchy']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('symptoms');
});

it('caps the number of photographs', function (): void {
    $photos = collect(range(1, config('nadara.uploads.consultation_image_max_files') + 1))
        ->map(fn (int $index): UploadedFile => UploadedFile::fake()->image("photo-{$index}.jpg"))
        ->all();

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.store'), consultationPayload(['photos' => $photos]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('photos');
});

it('keeps the consultation closed to the doctor until the payment is verified', function (): void {
    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.store'), consultationPayload())
        ->assertCreated();

    $consultation = Consultation::firstOrFail();

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.receipt.store', $consultation), [
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])
        ->assertCreated();

    // Still not answerable: a receipt was uploaded but nobody has confirmed it.
    expect($consultation->refresh()->status)->toBe(ConsultationStatus::PendingPayment);

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.payments.verify', Payment::firstOrFail()))
        ->assertOk();

    expect($consultation->refresh()->status)->toBe(ConsultationStatus::AwaitingReply);
});

it('marks the thread replied when the doctor answers', function (): void {
    $consultation = Consultation::factory()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'status' => ConsultationStatus::AwaitingReply,
    ]);

    $this->actingAs($this->doctor)
        ->postJson(route('api.v1.consultations.messages.store', $consultation), [
            'message' => 'That looks like contact dermatitis. Start with a mild steroid cream.',
        ])
        ->assertCreated();

    $consultation->refresh();

    expect($consultation->status)->toBe(ConsultationStatus::Replied)
        ->and($consultation->replied_at)->not->toBeNull()
        ->and($consultation->messages)->toHaveCount(1);
});

it('reopens the thread when the patient writes back', function (): void {
    $consultation = Consultation::factory()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'status' => ConsultationStatus::Replied,
    ]);

    $this->actingAs($this->patient)
        ->postJson(route('api.v1.consultations.messages.store', $consultation), [
            'message' => 'The cream helped but the patch came back this morning.',
        ])
        ->assertCreated();

    expect($consultation->refresh()->status)->toBe(ConsultationStatus::AwaitingReply);
});

it('hides a consultation from a doctor who is not on it', function (): void {
    $consultation = Consultation::factory()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->actingAs(User::factory()->doctor()->create())
        ->getJson(route('api.v1.consultations.show', $consultation))
        ->assertForbidden();
});

it('keeps symptoms away from the secretary', function (): void {
    $consultation = Consultation::factory()->create([
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $secretary = User::factory()->secretary($this->doctor)->create();

    $this->actingAs($secretary)
        ->getJson(route('api.v1.consultations.show', $consultation))
        ->assertForbidden();

    $this->actingAs($secretary)
        ->getJson(route('api.v1.consultations.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lists only the caller own consultations', function (): void {
    Consultation::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id]);
    Consultation::factory()->create();

    $this->actingAs($this->patient)
        ->getJson(route('api.v1.consultations.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
