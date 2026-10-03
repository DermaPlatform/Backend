<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\NotificationKind;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingNotAllowedException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ClinicUpdate;
use Illuminate\Support\Facades\DB;

/**
 * Cancellation, by the patient (US-014) or by the clinic (US-013).
 */
class CancellationService
{
    /**
     * Release the slot and record why.
     *
     * @throws BookingNotAllowedException when the appointment can no longer be cancelled
     */
    public function cancel(Appointment $appointment, ?string $reason): Appointment
    {
        $this->assertOpen($appointment);
        $this->assertInsideCancellationWindow($appointment);

        return DB::transaction(fn (): Appointment => $this->close($appointment, $reason));
    }

    /**
     * Cancel on the clinic's side: the doctor is away, the room is closed.
     *
     * The patient's notice period does not apply, since it exists to protect
     * the clinic from late withdrawals and this is the clinic withdrawing.
     * What it owes instead is a reason, which is why one is required.
     *
     * @throws BookingNotAllowedException when the appointment is already closed
     */
    public function cancelByClinic(Appointment $appointment, User $staff, string $reason): Appointment
    {
        $this->assertOpen($appointment);

        return DB::transaction(function () use ($appointment, $staff, $reason): Appointment {
            $cancelled = $this->close($appointment, $reason);

            AuditLog::record($staff, AuditAction::AppointmentCancelledByClinic, $cancelled, [
                'reason' => $reason,
                'refund_required' => $cancelled->refund_required,
            ]);

            $cancelled->patient->notify(new ClinicUpdate(NotificationKind::AppointmentCancelledByClinic, [
                'appointment_id' => $cancelled->id,
                'reason' => $reason,
                'refund_required' => $cancelled->refund_required,
            ]));

            return $cancelled;
        });
    }

    private function close(Appointment $appointment, ?string $reason): Appointment
    {
        /*
         * The slot is freed by the status alone: cancelled is not in
         * AppointmentStatus::blocking(), so availability stops counting it
         * the moment this commits. Clearing the hold keeps the row honest
         * rather than leaving a deadline on something already closed.
         */
        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'hold_expires_at' => null,
            'refund_required' => $appointment->payment?->status === PaymentStatus::Verified,
        ]);

        return $appointment->refresh();
    }

    /**
     * @throws BookingNotAllowedException
     */
    private function assertOpen(Appointment $appointment): void
    {
        $closed = [
            AppointmentStatus::Cancelled,
            AppointmentStatus::Rejected,
            AppointmentStatus::Expired,
            AppointmentStatus::Completed,
        ];

        if (in_array($appointment->status, $closed, true)) {
            throw new BookingNotAllowedException('This appointment is already closed.', 'status');
        }
    }

    /**
     * @throws BookingNotAllowedException
     */
    private function assertInsideCancellationWindow(Appointment $appointment): void
    {
        $deadline = $appointment->start_at->subHours((int) config('nadara.cancellation_window_hours'));

        if ($deadline->isPast()) {
            throw new BookingNotAllowedException(
                'Appointments can only be cancelled at least '
                    .config('nadara.cancellation_window_hours').' hours in advance.',
                'start_at',
            );
        }
    }
}
