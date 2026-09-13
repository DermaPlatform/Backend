<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingNotAllowedException;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;

/**
 * Patient initiated cancellation (US-014).
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
        $this->assertCancellable($appointment);

        return DB::transaction(function () use ($appointment, $reason): Appointment {
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
        });
    }

    /**
     * @throws BookingNotAllowedException
     */
    private function assertCancellable(Appointment $appointment): void
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
