<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingNotAllowedException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates the appointment itself (US-010).
 *
 * Everything the appointment needs to outlive later edits is copied onto the
 * row at this moment: the price, and the end time derived from the duration
 * the doctor set. Reading either back through the pricing table would let a
 * doctor rewrite the history of appointments already taken.
 */
class BookingService
{
    /**
     * Hold a slot for a patient, pending payment.
     *
     * @throws BookingNotAllowedException when this doctor cannot take the booking at all
     * @throws SlotUnavailableException when the slot was taken first
     */
    public function book(User $patient, User $doctor, Service $service, CarbonInterface $startAt): Appointment
    {
        $offering = $this->offeringFor($doctor, $service);

        $this->assertDoctorIsBookable($doctor);

        $start = CarbonImmutable::instance($startAt)->utc();
        $end = $start->addMinutes((int) $offering->pivot->duration_minutes);

        if ($start->isPast()) {
            throw new BookingNotAllowedException('That appointment time has already passed.', 'start_at');
        }

        return DB::transaction(function () use ($patient, $doctor, $service, $offering, $start, $end): Appointment {
            /*
             * Locking the doctor's rows for the surrounding day rather than the
             * slot alone is deliberate: on an indexed range scan InnoDB also
             * holds the gaps, which is what stops a second transaction from
             * inserting into a slot this one has just found empty.
             */
            $taken = Appointment::query()
                ->where('doctor_id', $doctor->id)
                ->whereBetween('start_at', [$start->startOfDay(), $start->endOfDay()])
                ->overlapping($start, $end)
                ->occupyingSlot()
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw new SlotUnavailableException;
            }

            return Appointment::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'start_at' => $start,
                'end_at' => $end,
                'price' => $offering->pivot->price,
                'status' => AppointmentStatus::PendingPayment,
                'hold_expires_at' => now()->addMinutes((int) config('nadara.booking_hold_minutes')),
            ]);
        });
    }

    /**
     * The doctor's own pricing row for this service, which is the only source
     * of truth for what an appointment costs and how long it runs.
     *
     * @throws BookingNotAllowedException
     */
    private function offeringFor(User $doctor, Service $service): Service
    {
        $offering = $doctor->offeredServices()
            ->wherePivot('service_id', $service->id)
            ->wherePivot('is_active', true)
            ->first();

        if ($offering === null) {
            throw new BookingNotAllowedException('This doctor does not offer that service.', 'service_id');
        }

        return $offering;
    }

    /**
     * @throws BookingNotAllowedException
     */
    private function assertDoctorIsBookable(User $doctor): void
    {
        if (! $doctor->is_active) {
            throw new BookingNotAllowedException('This doctor is not accepting patients right now.');
        }

        $profile = $doctor->doctorProfile;

        if ($profile === null || ! $profile->accepts_appointments) {
            throw new BookingNotAllowedException('This doctor is not accepting appointments right now.');
        }

        if (! $profile->hasActiveSubscription()) {
            throw new BookingNotAllowedException('This doctor is not accepting patients right now.');
        }
    }
}
