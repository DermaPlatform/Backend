<?php

namespace App\Enums;

enum ConsultationStatus: string
{
    case PendingPayment = 'pending_payment';
    case AwaitingReply = 'awaiting_reply';
    case Replied = 'replied';
    case Closed = 'closed';
}
