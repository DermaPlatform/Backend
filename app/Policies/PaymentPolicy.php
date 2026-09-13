<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;

/**
 * Who may see and settle a receipt.
 *
 * The money side is exactly where the secretary belongs, so unlike the
 * clinical record they are a full participant here, for their own doctor only.
 */
class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        if ($user->role === UserRole::Patient) {
            return $user->id === $payment->patient_id;
        }

        return $this->review($user, $payment);
    }

    public function review(User $user, Payment $payment): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Doctor => $user->id === $this->doctorIdFor($payment),
            UserRole::Secretary => $user->doctor_id !== null
                && $user->doctor_id === $this->doctorIdFor($payment),
            default => false,
        };
    }

    /**
     * A payment hangs off either an appointment or a consultation, and the
     * doctor it belongs to lives on whichever one is set.
     */
    private function doctorIdFor(Payment $payment): ?int
    {
        return $payment->appointment?->doctor_id ?? $payment->consultation?->doctor_id;
    }
}
