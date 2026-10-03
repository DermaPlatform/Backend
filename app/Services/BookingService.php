<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Exceptions\BookingNotAllowedException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\AuditLog;
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
    public function __construct(
        private readonly PaymentService $payments,
        private readonly ScheduleService $schedules,
    ) {}

    /**
     * Hold a slot for a patient, pending payment.
     *
     * @throws BookingNotAllowedException when this doctor cannot take the booking at all
     * @throws SlotUnavailableException when the slot was taken first
     */
    public function book(User $patient, User $doctor, Service $service, CarbonInterface $startAt): Appointment
    {
        $slot = $this->slotFor($doctor, $service, $startAt);

        /*
         * Only the patient's own booking is held to the published hours. The
         * clinic booking a caller by phone may squeeze a visit in outside
         * them; that is the clinic's call to make.
         */
        if (! $this->schedules->isWorking($doctor->id, $slot['start'], $slot['end'])) {
            throw new BookingNotAllowedException('The doctor is not working at that time.', 'start_at');
        }

        return DB::transaction(function () use ($patient, $doctor, $service, $slot): Appointment {
            $this->assertHoldAllowance($patient);
            $this->assertSlotIsFree($doctor->id, $slot['start'], $slot['end']);

            return Appointment::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'start_at' => $slot['start'],
                'end_at' => $slot['end'],
                'price' => $slot['price'],
                'status' => AppointmentStatus::PendingPayment,
                'hold_expires_at' => now()->addMinutes((int) config('nadara.booking_hold_minutes')),
            ]);
        });
    }

    /**
     * Book for a patient who called the clinic (US-026).
     *
     * There is no hold and no receipt to wait for: the secretary is taking
     * the booking in person and the patient pays in cash, so the appointment
     * is confirmed and its payment settled in the same step.
     *
     * @throws BookingNotAllowedException when this doctor cannot take the booking at all
     * @throws SlotUnavailableException when the slot is already taken
     */
    public function bookByPhone(
        User $staff,
        User $patient,
        User $doctor,
        Service $service,
        CarbonInterface $startAt,
    ): Appointment {
        $slot = $this->slotFor($doctor, $service, $startAt);

        return DB::transaction(function () use ($staff, $patient, $doctor, $service, $slot): Appointment {
            $this->assertSlotIsFree($doctor->id, $slot['start'], $slot['end']);

            $appointment = Appointment::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'start_at' => $slot['start'],
                'end_at' => $slot['end'],
                'price' => $slot['price'],
                'status' => AppointmentStatus::Approved,
                'hold_expires_at' => null,
                'approved_by' => $staff->id,
                'approved_at' => now(),
            ]);

            $this->payments->recordCashPayment($appointment, $staff);

            AuditLog::record($staff, AuditAction::AppointmentBookedByPhone, $appointment, [
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'start_at' => $slot['start']->toIso8601String(),
                'price' => $slot['price'],
            ]);

            return $appointment;
        });
    }

    /**
     * Give an open appointment a different length (US-015).
     *
     * The doctor's listed duration is an estimate. The clinic knows that this
     * patient's session needs an hour, so the end time moves, as long as it
     * does not run into whoever is booked next.
     *
     * @throws BookingNotAllowedException when the appointment is already closed
     * @throws SlotUnavailableException when the new length overlaps another appointment
     */
    public function changeDuration(Appointment $appointment, User $staff, int $durationMinutes): Appointment
    {
        if (! in_array($appointment->status, AppointmentStatus::blocking(), true)) {
            throw new BookingNotAllowedException('Only an open appointment can be given a new duration.', 'status');
        }

        $start = $appointment->start_at;
        $end = $start->addMinutes($durationMinutes);

        return DB::transaction(function () use ($appointment, $staff, $start, $end, $durationMinutes): Appointment {
            $this->assertSlotIsFree(
                $appointment->doctor_id,
                $start,
                $end,
                except: $appointment,
                message: 'The new duration runs into another appointment.',
            );

            $previousMinutes = (int) $start->diffInMinutes($appointment->end_at);

            $appointment->update(['end_at' => $end]);

            AuditLog::record($staff, AuditAction::AppointmentDurationChanged, $appointment, [
                'from_minutes' => $previousMinutes,
                'to_minutes' => $durationMinutes,
            ]);

            return $appointment->refresh();
        });
    }

    /**
     * The window and price a booking of this service with this doctor takes.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, price: string}
     *
     * @throws BookingNotAllowedException
     */
    private function slotFor(User $doctor, Service $service, CarbonInterface $startAt): array
    {
        $offering = $this->offeringFor($doctor, $service);

        $this->assertDoctorIsBookable($doctor);

        $start = CarbonImmutable::instance($startAt)->utc();

        if ($start->isPast()) {
            throw new BookingNotAllowedException('That appointment time has already passed.', 'start_at');
        }

        return [
            'start' => $start,
            'end' => $start->addMinutes((int) $offering->pivot->duration_minutes),
            'price' => $offering->pivot->price,
        ];
    }

    /**
     * Must run inside the transaction that then writes the appointment.
     *
     * @throws SlotUnavailableException
     */
    private function assertSlotIsFree(
        int $doctorId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?Appointment $except = null,
        ?string $message = null,
    ): void {
        /*
         * Locking the doctor's rows for the surrounding day rather than the
         * slot alone is deliberate: on an indexed range scan InnoDB also
         * holds the gaps, which is what stops a second transaction from
         * inserting into a slot this one has just found empty.
         */
        $taken = Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereBetween('start_at', [$start->startOfDay(), $start->endOfDay()])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->overlapping($start, $end)
            ->occupyingSlot()
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            throw $message === null ? new SlotUnavailableException : new SlotUnavailableException($message);
        }
    }

    /**
     * Refuse another unpaid hold once the patient already has the maximum
     * running. Paying for or cancelling one frees the allowance again.
     *
     * @throws BookingNotAllowedException
     */
    private function assertHoldAllowance(User $patient): void
    {
        $limit = (int) config('nadara.max_open_holds_per_patient');

        $openHolds = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('status', AppointmentStatus::PendingPayment)
            ->where('hold_expires_at', '>', now())
            ->lockForUpdate()
            ->count();

        if ($openHolds >= $limit) {
            throw new BookingNotAllowedException(
                'You already have unpaid bookings waiting. Upload their receipts or cancel them before booking again.',
                'start_at',
            );
        }
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
