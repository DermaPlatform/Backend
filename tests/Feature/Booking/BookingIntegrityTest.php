<?php

use App\Enums\AppointmentStatus;
use App\Enums\ConsultationStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\DoctorSchedule;
use App\Models\Payment;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Holes in the booking and payment flow found in the QA pass: requests the
 * screens never send, but the API used to accept.
 */
beforeEach(function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));

    $this->timezone = config('nadara.clinic_timezone');
    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00', $this->timezone));

    $this->patient = User::factory()->patient()->create();
    $this->doctor = User::factory()->doctor()->create();
    $this->secretary = User::factory()->secretary($this->doctor)->create();
    $this->service = Service::factory()->create();

    $this->doctor->offeredServices()->attach($this->service->id, [
        'price' => 100.00,
        'duration_minutes' => 30,
        'is_active' => true,
    ]);

    // A Sunday, 09:00 to 13:00.
    $this->day = CarbonImmutable::parse('2026-09-20', $this->timezone);

    DoctorSchedule::factory()->create([
        'doctor_id' => $this->doctor->id,
        'weekday' => $this->day->dayOfWeek,
        'starts_at' => '09:00:00',
        'ends_at' => '13:00:00',
        'slot_minutes' => 30,
    ]);
});

function bookAt(CarbonImmutable $startAt): TestResponse
{
    return test()->actingAsPortal(test()->patient)->postJson(route('api.v1.appointments.store'), [
        'doctor_id' => test()->doctor->id,
        'service_id' => test()->service->id,
        'start_at' => $startAt->toIso8601String(),
    ]);
}

describe('working hours', function (): void {
    it('books a time inside a shift', function (): void {
        bookAt($this->day->setTime(10, 0))->assertCreated();
    });

    it('refuses a time outside every shift', function (string $time): void {
        bookAt($this->day->setTimeFromTimeString($time))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_at');

        expect(Appointment::count())->toBe(0);
    })->with(['03:00', '08:30', '13:00', '12:45']);

    it('refuses a weekday the doctor does not work', function (): void {
        bookAt($this->day->addDay()->setTime(10, 0))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_at');
    });

    it('refuses a day the doctor has taken off', function (): void {
        ScheduleException::factory()->fullDay()->create([
            'doctor_id' => $this->doctor->id,
            'date' => $this->day->toDateString(),
        ]);

        bookAt($this->day->setTime(10, 0))->assertUnprocessable();
    });

    it('refuses hours the doctor has closed on that date', function (): void {
        ScheduleException::factory()->create([
            'doctor_id' => $this->doctor->id,
            'date' => $this->day->toDateString(),
            'starts_at' => '10:00:00',
            'ends_at' => '11:00:00',
        ]);

        bookAt($this->day->setTime(9, 45))->assertUnprocessable();
        bookAt($this->day->setTime(11, 0))->assertCreated();
    });

    it('still lets the clinic book a caller outside the published hours', function (): void {
        $this->actingAsPortal($this->secretary)
            ->postJson(route('api.v1.staff.appointments.store'), [
                'patient_phone' => '+970599111222',
                'patient_name' => 'Caller',
                'service_id' => $this->service->id,
                'start_at' => $this->day->setTime(15, 0)->toIso8601String(),
            ])
            ->assertCreated();
    });
});

describe('appointment receipts', function (): void {
    it('refuses a receipt once the hold has lapsed, and settles the hold', function (): void {
        $appointment = Appointment::factory()->holdExpired()->create([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
        ]);

        $this->actingAsPortal($this->patient)
            ->postJson(route('api.v1.appointments.receipt.store', $appointment), [
                'receipt' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Expired)
            ->and(Payment::count())->toBe(0);
    });

    it('refuses to settle a receipt whose appointment was cancelled meanwhile', function (string $action): void {
        $appointment = Appointment::factory()->awaitingReview()->create([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'start_at' => $this->day->setTime(10, 0),
            'end_at' => $this->day->setTime(10, 30),
        ]);
        $payment = Payment::factory()->create([
            'appointment_id' => $appointment->id,
            'patient_id' => $this->patient->id,
            'status' => PaymentStatus::Submitted,
            'receipt_path' => 'receipts/slip.jpg',
        ]);

        $this->actingAsPortal($this->patient)
            ->postJson(route('api.v1.appointments.cancel', $appointment))
            ->assertOk()
            ->assertJsonPath('data.refund_required', true);

        $this->actingAsPortal($this->secretary)
            ->postJson(route('api.v1.payments.'.$action, $payment), ['reason' => 'Wrong amount'])
            ->assertUnprocessable();

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Submitted);
    })->with(['verify', 'reject']);
});

describe('consultations', function (): void {
    beforeEach(function (): void {
        $this->consultation = Consultation::factory()->create([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'status' => ConsultationStatus::PendingPayment,
        ]);
    });

    it('keeps the thread shut until the payment is confirmed', function (): void {
        $this->actingAsPortal($this->patient)
            ->postJson(route('api.v1.consultations.messages.store', $this->consultation), [
                'message' => 'Any update on this please?',
            ])
            ->assertUnprocessable();

        expect($this->consultation->refresh()->status)->toBe(ConsultationStatus::PendingPayment)
            ->and($this->consultation->messages()->count())->toBe(0);
    });

    it('refuses messages on a closed thread', function (): void {
        $this->consultation->update(['status' => ConsultationStatus::Closed]);

        $this->actingAsPortal($this->doctor)
            ->postJson(route('api.v1.consultations.messages.store', $this->consultation), [
                'message' => 'One more thing.',
            ])
            ->assertUnprocessable();
    });

    it('refuses a new receipt once one is under review or verified', function (PaymentStatus $status, ConsultationStatus $consultationStatus): void {
        $this->consultation->update(['status' => $consultationStatus]);
        $payment = Payment::factory()->create([
            'appointment_id' => null,
            'consultation_id' => $this->consultation->id,
            'amount' => 80,
            'patient_id' => $this->patient->id,
            'status' => $status,
            'receipt_path' => 'receipts/first.jpg',
        ]);

        $this->actingAsPortal($this->patient)
            ->postJson(route('api.v1.consultations.receipt.store', $this->consultation), [
                'receipt' => UploadedFile::fake()->image('second.jpg'),
            ])
            ->assertUnprocessable();

        expect($payment->refresh()->status)->toBe($status)
            ->and($payment->receipt_path)->toBe('receipts/first.jpg');
    })->with([
        'under review' => [PaymentStatus::Submitted, ConsultationStatus::PendingPayment],
        'verified' => [PaymentStatus::Verified, ConsultationStatus::AwaitingReply],
    ]);

    it('accepts a fresh receipt after the last one was rejected', function (): void {
        Payment::factory()->create([
            'appointment_id' => null,
            'consultation_id' => $this->consultation->id,
            'amount' => 80,
            'patient_id' => $this->patient->id,
            'status' => PaymentStatus::Rejected,
            'receipt_path' => 'receipts/first.jpg',
        ]);

        $this->actingAsPortal($this->patient)
            ->postJson(route('api.v1.consultations.receipt.store', $this->consultation), [
                'receipt' => UploadedFile::fake()->image('second.jpg'),
            ])
            ->assertCreated();
    });

    it('lets the secretary open a consultation receipt to review it, without the consultation', function (): void {
        Storage::disk(config('nadara.uploads.private_disk'))->put('receipts/slip.jpg', 'image');
        $payment = Payment::factory()->create([
            'appointment_id' => null,
            'consultation_id' => $this->consultation->id,
            'amount' => 80,
            'patient_id' => $this->patient->id,
            'status' => PaymentStatus::Submitted,
            'receipt_path' => 'receipts/slip.jpg',
        ]);

        $this->actingAsPortal($this->secretary)
            ->getJson(route('api.v1.staff.payments.index'))
            ->assertJsonPath('data.0.receipt_url', route('api.v1.payments.receipt.show', $payment));

        $this->actingAsPortal($this->secretary)
            ->get(route('api.v1.payments.receipt.show', $payment))
            ->assertOk();

        $this->actingAsPortal($this->secretary)
            ->getJson(route('api.v1.consultations.show', $this->consultation))
            ->assertForbidden();
    });

    it('keeps a payment receipt away from another clinic and another patient', function (): void {
        $payment = Payment::factory()->create([
            'appointment_id' => null,
            'consultation_id' => $this->consultation->id,
            'amount' => 80,
            'patient_id' => $this->patient->id,
            'status' => PaymentStatus::Submitted,
            'receipt_path' => 'receipts/slip.jpg',
        ]);

        $otherDoctor = User::factory()->doctor()->create();

        foreach ([$otherDoctor, User::factory()->secretary($otherDoctor)->create(), User::factory()->patient()->create()] as $stranger) {
            $this->actingAsPortal($stranger)
                ->getJson(route('api.v1.payments.receipt.show', $payment))
                ->assertForbidden();
        }
    });
});
