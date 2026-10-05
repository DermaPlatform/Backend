<?php

use App\Enums\ConsultationStatus;
use App\Enums\NotificationKind;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ClinicUpdate;

/**
 * In-app notifications: what triggers one, and reading them.
 */
beforeEach(function (): void {
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patient()->create();

    $this->appointment = Appointment::factory()->awaitingReview()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
    ]);
    $this->payment = Payment::factory()->create([
        'appointment_id' => $this->appointment->id,
        'status' => PaymentStatus::Submitted,
        'receipt_path' => 'receipts/slip.jpg',
    ]);
});

function latestNotice(User $user): array
{
    return $user->notifications()->latest()->firstOrFail()->data;
}

it('tells the patient their appointment is confirmed once the receipt is verified', function (): void {
    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.payments.verify', $this->payment))
        ->assertOk();

    expect($this->patient->notifications()->count())->toBe(1)
        ->and(latestNotice($this->patient))->toBe([
            'kind' => NotificationKind::AppointmentConfirmed->value,
            'appointment_id' => $this->appointment->id,
        ])
        ->and($this->doctor->notifications()->count())->toBe(0);
});

it('tells the patient why a receipt was rejected', function (): void {
    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.payments.reject', $this->payment), ['reason' => 'الصورة غير واضحة'])
        ->assertOk();

    expect(latestNotice($this->patient))->toBe([
        'kind' => NotificationKind::PaymentRejected->value,
        'appointment_id' => $this->appointment->id,
        'reason' => 'الصورة غير واضحة',
    ]);
});

it('tells the patient when the clinic cancels', function (): void {
    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.staff.appointments.cancel', $this->appointment), ['reason' => 'الطبيب مسافر'])
        ->assertOk();

    expect(latestNotice($this->patient))->toBe([
        'kind' => NotificationKind::AppointmentCancelledByClinic->value,
        'appointment_id' => $this->appointment->id,
        'reason' => 'الطبيب مسافر',
        'refund_required' => true,
    ]);
});

it('tells the patient when a consultation opens and when the doctor answers', function (): void {
    $consultation = Consultation::factory()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->patient->id,
    ]);
    $payment = Payment::factory()->create([
        'appointment_id' => null,
        'consultation_id' => $consultation->id,
        'patient_id' => $this->patient->id,
        'amount' => 40,
        'status' => PaymentStatus::Submitted,
    ]);

    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.payments.verify', $payment))
        ->assertOk();

    expect(latestNotice($this->patient))->toBe([
        'kind' => NotificationKind::ConsultationOpened->value,
        'consultation_id' => $consultation->id,
    ]);

    $this->postJson(route('api.v1.consultations.messages.store', $consultation), ['message' => 'Use the cream twice daily.'])
        ->assertCreated();

    expect($this->patient->notifications()->count())->toBe(2)
        ->and($consultation->refresh()->status)->toBe(ConsultationStatus::Replied);

    // The patient writing back notifies nobody, and does not move the first answer's date.
    $firstAnswerAt = $consultation->replied_at;
    $this->travel(2)->days();

    $this->actingAsPortal($this->patient)
        ->postJson(route('api.v1.consultations.messages.store', $consultation), ['message' => 'For how long?'])
        ->assertCreated();
    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.consultations.messages.store', $consultation), ['message' => 'Two weeks.'])
        ->assertCreated();

    expect($this->patient->notifications()->count())->toBe(3)
        ->and($this->doctor->notifications()->count())->toBe(0)
        ->and($consultation->refresh()->replied_at->equalTo($firstAnswerAt))->toBeTrue();
});

it('lists the caller\'s own notifications with an unread count', function (): void {
    $this->patient->notify(new ClinicUpdate(NotificationKind::AppointmentConfirmed, ['appointment_id' => 1]));
    $this->travel(1)->minutes();
    $this->patient->notify(new ClinicUpdate(NotificationKind::PaymentRejected, ['appointment_id' => 2, 'reason' => 'x']));
    $this->doctor->notify(new ClinicUpdate(NotificationKind::ConsultationReplied, ['consultation_id' => 9]));

    $this->getJson(route('api.v1.notifications.index'))->assertUnauthorized();

    $response = $this->actingAsPortal($this->patient)
        ->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('unread_count', 2)
        ->assertJsonPath('data.0.kind', 'payment.rejected')
        ->assertJsonPath('data.0.details.reason', 'x')
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.1.kind', 'appointment.confirmed');

    $this->postJson(route('api.v1.notifications.read', $response->json('data.0.id')))
        ->assertOk()
        ->assertJsonPath('data.kind', 'payment.rejected');

    $this->getJson(route('api.v1.notifications.index', ['unread' => 1]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('data.0.kind', 'appointment.confirmed');

    $this->postJson(route('api.v1.notifications.read-all'))->assertOk();

    $this->getJson(route('api.v1.notifications.index'))
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('unread_count', 0);
});

it('cannot read somebody else\'s notification', function (): void {
    $this->patient->notify(new ClinicUpdate(NotificationKind::AppointmentConfirmed, ['appointment_id' => 1]));
    $notice = $this->patient->notifications()->sole();

    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.notifications.read', $notice->id))
        ->assertNotFound();

    expect($notice->refresh()->read_at)->toBeNull();
});
