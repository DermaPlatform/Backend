<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DestroyPushSubscriptionRequest;
use App\Http\Requests\Api\V1\StorePushSubscriptionRequest;
use App\Models\User;
use App\Notifications\Channels\DeferredWebPushChannel;
use Illuminate\Http\JsonResponse;

/**
 * The devices a user wants their notifications pushed to.
 *
 * A subscription is what a browser hands back once its user allows
 * notifications: an address at the browser vendor's push service and the
 * keys to encrypt for that one device. Everything goes through the caller's
 * own relation, so nobody can list, replace or remove another user's device.
 */
class PushSubscriptionController extends Controller
{
    /**
     * The public half of the server's VAPID key pair, which a browser needs
     * before it can subscribe. Null means push is switched off on this
     * server, and the front end hides the option instead of failing.
     */
    public function vapidKey(): JsonResponse
    {
        return response()->json([
            'data' => [
                'public_key' => DeferredWebPushChannel::isConfigured() ? config('webpush.vapid.public_key') : null,
            ],
        ]);
    }

    /**
     * Register this device, or refresh the keys of one already known.
     *
     * Safe to repeat: the front end sends the subscription again on every
     * start so the two sides cannot drift apart. An address last used by
     * somebody else on a shared browser moves to the caller.
     */
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->updatePushSubscription(
            $request->validated('endpoint'),
            $request->validated('keys.p256dh'),
            $request->validated('keys.auth'),
            $request->validated('content_encoding'),
        );

        $this->forgetOldestDevices($user);

        return response()->json([
            'message' => 'Push subscription saved.',
        ]);
    }

    /**
     * Stop pushing to this device. Removing one that is already gone is not
     * an error: the caller wanted it gone either way.
     */
    public function destroy(DestroyPushSubscriptionRequest $request): JsonResponse
    {
        $request->user()->deletePushSubscription($request->validated('endpoint'));

        return response()->json([
            'message' => 'Push subscription removed.',
        ]);
    }

    /**
     * Keep the most recently used devices and drop the rest, so one account
     * cannot grow an unbounded list that every notification then fans out to.
     */
    private function forgetOldestDevices(User $user): void
    {
        $surplus = $user->pushSubscriptions()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->slice((int) config('nadara.push.max_devices_per_user'));

        if ($surplus->isNotEmpty()) {
            $user->pushSubscriptions()->whereKey($surplus)->delete();
        }
    }
}
