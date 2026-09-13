<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;

/**
 * Who may see and act on an appointment.
 *
 * Four roles now share this record. A patient sees only their own bookings, a
 * doctor only their own calendar, a secretary only the calendar of the one
 * doctor they work for, and an admin sees everything.
 */
class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Patient => $user->id === $appointment->patient_id,
            UserRole::Doctor => $user->id === $appointment->doctor_id,
            UserRole::Secretary => $this->worksFor($user, $appointment->doctor_id),
            default => false,
        };
    }

    /**
     * Only a patient books, and only for themselves.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Patient && $user->is_active;
    }

    /**
     * A patient may withdraw their own booking. Staff cancellation is a
     * separate operation with its own reason trail, not this one.
     */
    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->role === UserRole::Patient && $user->id === $appointment->patient_id;
    }

    /**
     * Uploading the receipt is the patient's own step.
     */
    public function payFor(User $user, Appointment $appointment): bool
    {
        return $user->role === UserRole::Patient && $user->id === $appointment->patient_id;
    }

    /**
     * Approving or rejecting a receipt is a staff decision. A secretary may do
     * it for their own doctor, which is the whole point of the role.
     */
    public function review(User $user, Appointment $appointment): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Doctor => $user->id === $appointment->doctor_id,
            UserRole::Secretary => $this->worksFor($user, $appointment->doctor_id),
            default => false,
        };
    }

    /**
     * A secretary is bound to exactly one doctor.
     *
     * The null check is not defensive noise. Without it a secretary whose
     * doctor_id is null would match every appointment whose doctor_id is also
     * null, and the comparison would read as a pass.
     */
    private function worksFor(User $user, ?int $doctorId): bool
    {
        return $user->doctor_id !== null && $user->doctor_id === $doctorId;
    }
}
