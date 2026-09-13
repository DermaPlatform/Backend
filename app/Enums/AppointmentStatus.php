<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case PendingPayment = 'pending_payment';
    case AwaitingReview = 'awaiting_review';
    case Approved = 'approved';
    case Completed = 'completed';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Statuses that occupy a calendar slot and must be subtracted from availability.
     *
     * @return array<int, self>
     */
    public static function blocking(): array
    {
        return [self::PendingPayment, self::AwaitingReview, self::Approved];
    }
}
