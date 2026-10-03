<?php

namespace App\Enums;

/**
 * The staff decisions the platform keeps a trail of.
 *
 * These are the moments money or access changes hands on a person's word:
 * a receipt confirmed by eye, an account opened or shut, a subscription
 * extended after a transfer nobody else saw.
 */
enum AuditAction: string
{
    case PaymentVerified = 'payment.verified';
    case PaymentRejected = 'payment.rejected';
    case AccountCreated = 'account.created';
    case AccountActivated = 'account.activated';
    case AccountDeactivated = 'account.deactivated';
    case AccountPasswordReset = 'account.password_reset';
    case SubscriptionExtended = 'subscription.extended';
}
