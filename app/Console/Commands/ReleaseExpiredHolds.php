<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Console\Command;

/**
 * Retires bookings whose payment window ran out.
 *
 * Availability already ignores a lapsed hold, so this command does not free
 * the slot; the slot is free the moment the deadline passes. What it does is
 * settle the row, so a patient sees "expired" instead of a booking that looks
 * live but can no longer be paid for, and so reporting is not left counting
 * holds that were never going to complete.
 */
class ReleaseExpiredHolds extends Command
{
    protected $signature = 'appointments:release-expired-holds';

    protected $description = 'Expire appointments whose payment hold has run out';

    public function handle(): int
    {
        $released = 0;

        Appointment::query()
            ->where('status', AppointmentStatus::PendingPayment)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->chunkById(200, function ($appointments) use (&$released): void {
                foreach ($appointments as $appointment) {
                    $appointment->update([
                        'status' => AppointmentStatus::Expired,
                        'hold_expires_at' => null,
                    ]);

                    $released++;
                }
            });

        $this->info("Released {$released} expired hold(s).");

        return self::SUCCESS;
    }
}
