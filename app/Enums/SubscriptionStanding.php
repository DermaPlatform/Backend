<?php

namespace App\Enums;

/**
 * Where a doctor's subscription stands right now.
 *
 * SubscriptionStatus is what the admin recorded; this is what that record
 * means today. Grace exists only here: it is worked out from the end date at
 * read time, so nothing has to flip a column at midnight.
 */
enum SubscriptionStanding: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';

    /**
     * Whether a doctor in this standing may still take new bookings.
     */
    public function takesNewWork(): bool
    {
        return $this !== self::Expired;
    }
}
