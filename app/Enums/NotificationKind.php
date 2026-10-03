<?php

namespace App\Enums;

/**
 * What an in-app notification is telling its reader.
 *
 * The API sends the kind and the ids, not a sentence. The wording belongs to
 * the front end, which knows the reader's language.
 */
enum NotificationKind: string
{
    case AppointmentConfirmed = 'appointment.confirmed';
    case AppointmentCancelledByClinic = 'appointment.cancelled_by_clinic';
    case ConsultationOpened = 'consultation.opened';
    case ConsultationReplied = 'consultation.replied';
    case PaymentRejected = 'payment.rejected';
}
