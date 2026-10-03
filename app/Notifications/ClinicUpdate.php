<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use Illuminate\Notifications\Notification;

/**
 * Something happened to a patient's booking while they were not looking.
 *
 * Stored in the database and read inside the application. There is no email
 * or message behind it: a patient booked over the phone may have no address
 * at all, and the bell in the app works for everyone.
 */
class ClinicUpdate extends Notification
{
    /**
     * @param  array<string, mixed>  $details  Ids and facts the front end needs to word and link the notice.
     */
    public function __construct(
        private readonly NotificationKind $kind,
        private readonly array $details = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            ...$this->details,
        ];
    }
}
