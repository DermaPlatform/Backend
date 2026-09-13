<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Consultation;
use App\Models\User;

/**
 * Who may see and act on an online consultation.
 *
 * A consultation carries symptoms and photographs, so a secretary is
 * deliberately absent from every clinical check below: the role exists to run
 * the calendar and the money, not to read a diagnosis.
 */
class ConsultationPolicy
{
    public function view(User $user, Consultation $consultation): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Patient => $user->id === $consultation->patient_id,
            UserRole::Doctor => $user->id === $consultation->doctor_id,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Patient && $user->is_active;
    }

    /**
     * Both sides of a paid consultation may write in it. The patient asks
     * follow up questions, the doctor answers.
     */
    public function reply(User $user, Consultation $consultation): bool
    {
        return match ($user->role) {
            UserRole::Patient => $user->id === $consultation->patient_id,
            UserRole::Doctor => $user->id === $consultation->doctor_id,
            default => false,
        };
    }

    public function payFor(User $user, Consultation $consultation): bool
    {
        return $user->role === UserRole::Patient && $user->id === $consultation->patient_id;
    }
}
