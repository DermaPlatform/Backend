<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Notifications\Channels\DeferredWebPushChannel;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Something happened to a patient's booking while they were not looking.
 *
 * Stored in the database and read inside the application. There is no email
 * or message behind it: a patient booked over the phone may have no address
 * at all, and the bell in the app works for everyone.
 *
 * A patient who allowed notifications on a device also gets it pushed there,
 * so it reaches them with the application closed. The stored copy stays the
 * record; the push is only a faster way to hear about it.
 */
class ClinicUpdate extends Notification
{
    /**
     * How long a push service keeps trying a device that is offline. Past
     * three days the news is stale enough that the bell is the better place
     * to find it.
     */
    private const PUSH_TTL_SECONDS = 3 * 24 * 60 * 60;

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
        return DeferredWebPushChannel::isConfigured()
            ? ['database', DeferredWebPushChannel::class]
            : ['database'];
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

    /**
     * The same kind and ids the bell receives, and no sentence: the service
     * worker words the notice with the front end's own copy, so a patient
     * reads identical text in both places.
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->data([
                'id' => $this->id,
                'kind' => $this->kind->value,
                'details' => (object) $this->details,
            ])
            ->options(['TTL' => self::PUSH_TTL_SECONDS]);
    }
}
