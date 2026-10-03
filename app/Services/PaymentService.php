<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\ConsultationStatus;
use App\Enums\NotificationKind;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ClinicUpdate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The manual receipt flow (US-013): the patient transfers the money outside
 * the platform and uploads proof, staff confirm it by eye.
 */
class PaymentService
{
    /**
     * Attach a receipt to an appointment and move it into the review queue.
     */
    public function submitAppointmentReceipt(
        Appointment $appointment,
        UploadedFile $receipt,
        ?string $method,
        ?string $reference,
    ): Payment {
        return DB::transaction(function () use ($appointment, $receipt, $method, $reference): Payment {
            $payment = $this->receiptPayment(
                $appointment->payment()->lockForUpdate()->first(),
                [
                    'patient_id' => $appointment->patient_id,
                    'appointment_id' => $appointment->id,
                    'amount' => $appointment->price,
                ],
                $receipt,
                $method,
                $reference,
            );

            /*
             * The hold exists to stop an unpaid booking from sitting on a slot
             * forever. Once proof of payment is in, the slot is no longer
             * provisional and must survive until a human looks at it.
             */
            $appointment->update([
                'status' => AppointmentStatus::AwaitingReview,
                'hold_expires_at' => null,
            ]);

            return $payment;
        });
    }

    /**
     * Attach a receipt to a consultation. The consultation stays unanswered
     * until staff confirm the money, so the doctor never reads symptoms for
     * work that was not paid for.
     */
    public function submitConsultationReceipt(
        Consultation $consultation,
        UploadedFile $receipt,
        ?string $method,
        ?string $reference,
    ): Payment {
        return DB::transaction(fn (): Payment => $this->receiptPayment(
            $consultation->payment()->lockForUpdate()->first(),
            [
                'patient_id' => $consultation->patient_id,
                'consultation_id' => $consultation->id,
                'amount' => $consultation->price,
            ],
            $receipt,
            $method,
            $reference,
        ));
    }

    /**
     * Settle an appointment paid in cash at the desk.
     *
     * There is no receipt image to review, so the payment is written already
     * verified, under the name of the staff member who took the money. Call
     * it inside the transaction that creates the appointment.
     */
    public function recordCashPayment(Appointment $appointment, User $staff): Payment
    {
        return Payment::create([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'amount' => $appointment->price,
            'receipt_number' => $this->nextReceiptNumber(),
            'method' => 'cash',
            'status' => PaymentStatus::Verified,
            'verified_by' => $staff->id,
            'verified_at' => now(),
            'paid_at' => now(),
        ]);
    }

    /**
     * Confirm a receipt and release whatever it was paying for.
     */
    public function verify(Payment $payment, User $staff): Payment
    {
        return DB::transaction(function () use ($payment, $staff): Payment {
            $payment->update([
                'status' => PaymentStatus::Verified,
                'verified_by' => $staff->id,
                'verified_at' => now(),
                'paid_at' => now(),
                'rejection_reason' => null,
            ]);

            $payment->appointment?->update([
                'status' => AppointmentStatus::Approved,
                'approved_by' => $staff->id,
                'approved_at' => now(),
            ]);

            $payment->consultation?->update([
                'status' => ConsultationStatus::AwaitingReply,
            ]);

            AuditLog::record($staff, AuditAction::PaymentVerified, $payment, [
                'amount' => $payment->amount,
                'appointment_id' => $payment->appointment_id,
                'consultation_id' => $payment->consultation_id,
            ]);

            $payment->patient->notify(new ClinicUpdate(
                $payment->appointment_id !== null
                    ? NotificationKind::AppointmentConfirmed
                    : NotificationKind::ConsultationOpened,
                $this->subjectOf($payment),
            ));

            return $payment->refresh();
        });
    }

    /**
     * Turn a receipt down and send the booking back to the patient.
     */
    public function reject(Payment $payment, User $staff, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $staff, $reason): Payment {
            $payment->update([
                'status' => PaymentStatus::Rejected,
                'verified_by' => $staff->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ]);

            /*
             * A rejected receipt returns the appointment to pending_payment
             * with a fresh hold rather than killing it outright, so a patient
             * who photographed the wrong slip keeps their slot long enough to
             * send the right one.
             */
            $payment->appointment?->update([
                'status' => AppointmentStatus::PendingPayment,
                'hold_expires_at' => now()->addMinutes((int) config('nadara.booking_hold_minutes')),
                'rejection_reason' => $reason,
            ]);

            AuditLog::record($staff, AuditAction::PaymentRejected, $payment, [
                'reason' => $reason,
                'appointment_id' => $payment->appointment_id,
                'consultation_id' => $payment->consultation_id,
            ]);

            $payment->patient->notify(new ClinicUpdate(NotificationKind::PaymentRejected, [
                ...$this->subjectOf($payment),
                'reason' => $reason,
            ]));

            return $payment->refresh();
        });
    }

    /**
     * What a payment was for, as a notification points at it.
     *
     * @return array<string, int>
     */
    private function subjectOf(Payment $payment): array
    {
        return array_filter([
            'appointment_id' => $payment->appointment_id,
            'consultation_id' => $payment->consultation_id,
        ]);
    }

    /**
     * Create or update the payment row carrying the receipt.
     *
     * @param  array<string, mixed>  $identity
     */
    private function receiptPayment(
        ?Payment $existing,
        array $identity,
        UploadedFile $receipt,
        ?string $method,
        ?string $reference,
    ): Payment {
        $payment = $existing ?? new Payment($identity);

        $previousPath = $payment->receipt_path;

        $payment->fill([
            ...$identity,
            'receipt_path' => $this->storeReceipt($receipt),
            'receipt_number' => $payment->receipt_number ?? $this->nextReceiptNumber(),
            'method' => $method,
            'reference' => $reference,
            'status' => PaymentStatus::Submitted,
            'rejection_reason' => null,
        ])->save();

        if ($previousPath !== null) {
            Storage::disk(config('nadara.uploads.private_disk'))->delete($previousPath);
        }

        return $payment;
    }

    /**
     * Store the receipt under a generated name on the private disk.
     *
     * The client's filename never reaches the filesystem: it is attacker
     * controlled and is the usual way a path traversal or a double extension
     * gets in.
     */
    private function storeReceipt(UploadedFile $receipt): string
    {
        return $receipt->store(
            config('nadara.uploads.receipt_directory'),
            config('nadara.uploads.private_disk'),
        );
    }

    /**
     * The next receipt number for this year, as NDR-2026-000001.
     *
     * Called only from inside the transaction that writes the payment. The
     * lock on the year's highest row is what stops two simultaneous uploads
     * from generating the same number and colliding on the unique index.
     */
    private function nextReceiptNumber(): string
    {
        $year = now()->year;
        $prefix = config('nadara.receipt_number_prefix').'-'.$year.'-';

        $latest = Payment::query()
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('receipt_number')
            ->lockForUpdate()
            ->value('receipt_number');

        $sequence = $latest === null ? 0 : (int) mb_substr($latest, mb_strlen($prefix));

        return $prefix.str_pad((string) ($sequence + 1), 6, '0', STR_PAD_LEFT);
    }
}
