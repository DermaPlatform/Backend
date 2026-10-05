<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Web push, sent once the response is already on its way.
 *
 * Pushing means one HTTPS call per device to the browser vendor's service.
 * Done inline, the doctor verifying a receipt would wait on Google or Apple
 * before seeing their own result, and done on a queue it would depend on a
 * worker being up. Deferring needs neither, and a request that fails rolls
 * its transaction back and never runs the deferred work at all.
 *
 * A push that cannot be delivered is reported and otherwise ignored: the
 * notification is already stored, and the bell in the app still shows it.
 */
class DeferredWebPushChannel
{
    /**
     * Whether this server holds the VAPID key pair it needs to push at all.
     * Without it the feature is simply off: nothing is sent, and the front
     * end is told not to offer it.
     */
    public static function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    /**
     * The real channel is built inside the deferred work, not before it: a
     * malformed key makes its construction throw, and that must cost a push,
     * not the request that triggered it.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        defer(fn () => rescue(function () use ($notifiable, $notification): void {
            $notifiable->loadMissing('pushSubscriptions');

            app(WebPushChannel::class)->send($notifiable, $notification);
        }));
    }
}
