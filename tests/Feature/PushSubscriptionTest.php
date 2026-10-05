<?php

use App\Enums\NotificationKind;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Channels\DeferredWebPushChannel;
use App\Notifications\ClinicUpdate;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Web push: registering a device, and what is sent to it.
 */
const PUSH_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-one';

beforeEach(function (): void {
    $this->patient = User::factory()->patient()->create();
});

function subscriptionPayload(string $endpoint = PUSH_ENDPOINT): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
        'content_encoding' => 'aes128gcm',
    ];
}

function enablePush(): void
{
    config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);
}

it('hands out the public key only when push is configured', function (): void {
    $this->actingAsPortal($this->patient)
        ->getJson(route('api.v1.push-subscriptions.vapid-key'))
        ->assertOk()
        ->assertExactJson(['data' => ['public_key' => null]]);

    enablePush();

    $this->getJson(route('api.v1.push-subscriptions.vapid-key'))
        ->assertOk()
        ->assertExactJson(['data' => ['public_key' => 'public']]);
});

it('registers a device for the caller, and repeating it changes nothing', function (): void {
    $this->actingAsPortal($this->patient);

    $this->postJson(route('api.v1.push-subscriptions.store'), subscriptionPayload())->assertOk();
    $this->postJson(route('api.v1.push-subscriptions.store'), subscriptionPayload())->assertOk();

    expect($this->patient->pushSubscriptions()->count())->toBe(1)
        ->and($this->patient->pushSubscriptions()->first())
        ->endpoint->toBe(PUSH_ENDPOINT)
        ->public_key->toBe('public-key')
        ->auth_token->toBe('auth-token');
});

it('moves a device to whoever signed in on it last', function (): void {
    $other = User::factory()->patient()->create();
    $other->updatePushSubscription(PUSH_ENDPOINT, 'old-key', 'old-token');

    $this->actingAsPortal($this->patient)
        ->postJson(route('api.v1.push-subscriptions.store'), subscriptionPayload())
        ->assertOk();

    expect($other->pushSubscriptions()->count())->toBe(0)
        ->and($this->patient->pushSubscriptions()->count())->toBe(1);
});

it('refuses an address that is not a browser push service', function (string $endpoint): void {
    $this->actingAsPortal($this->patient)
        ->postJson(route('api.v1.push-subscriptions.store'), subscriptionPayload($endpoint))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');

    expect($this->patient->pushSubscriptions()->count())->toBe(0);
})->with([
    'an internal host' => 'https://169.254.169.254/latest/meta-data',
    'an arbitrary site' => 'https://example.com/push',
    'a lookalike host' => 'https://evil-fcm.googleapis.com.example.com/send',
    'plain http' => 'http://fcm.googleapis.com/fcm/send/device-one',
]);

it('accepts the push services of every supported browser', function (string $endpoint): void {
    $this->actingAsPortal($this->patient)
        ->postJson(route('api.v1.push-subscriptions.store'), subscriptionPayload($endpoint))
        ->assertOk();
})->with([
    'Chrome' => 'https://fcm.googleapis.com/fcm/send/abc',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/abc',
    'Edge' => 'https://wns2-by3p.notify.windows.com/w/?token=abc',
    'Safari' => 'https://web.push.apple.com/abc',
]);

it('keeps only the most recent devices of one account', function (): void {
    config(['nadara.push.max_devices_per_user' => 2]);
    $this->actingAsPortal($this->patient);

    foreach (['one', 'two', 'three'] as $index => $device) {
        $this->travelTo(now()->addMinutes($index));
        $this->postJson(
            route('api.v1.push-subscriptions.store'),
            subscriptionPayload("https://fcm.googleapis.com/fcm/send/{$device}")
        )->assertOk();
    }

    expect($this->patient->pushSubscriptions()->orderBy('id')->pluck('endpoint')->all())->toBe([
        'https://fcm.googleapis.com/fcm/send/two',
        'https://fcm.googleapis.com/fcm/send/three',
    ]);
});

it('removes only the caller\'s own device', function (): void {
    $other = User::factory()->patient()->create();
    $other->updatePushSubscription('https://fcm.googleapis.com/fcm/send/theirs');
    $this->patient->updatePushSubscription(PUSH_ENDPOINT);

    $this->actingAsPortal($this->patient);

    $this->deleteJson(route('api.v1.push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/theirs'])
        ->assertOk();
    expect($other->pushSubscriptions()->count())->toBe(1);

    $this->deleteJson(route('api.v1.push-subscriptions.destroy'), ['endpoint' => PUSH_ENDPOINT])->assertOk();
    expect($this->patient->pushSubscriptions()->count())->toBe(0);
});

it('stops pushing to every device once the account signs out everywhere', function (): void {
    $this->patient->updatePushSubscription(PUSH_ENDPOINT);

    $this->actingAsPortal($this->patient)
        ->postJson(route('api.v1.auth.logout-all'))
        ->assertOk();

    expect($this->patient->pushSubscriptions()->count())->toBe(0);
});

it('stays on the in-app bell alone while push is not configured', function (): void {
    $notice = new ClinicUpdate(NotificationKind::AppointmentConfirmed);

    expect($notice->via($this->patient))->toBe(['database']);

    enablePush();

    expect($notice->via($this->patient))->toBe(['database', DeferredWebPushChannel::class]);
});

it('pushes the kind and ids to the patient\'s device, and leaves the wording to the front end', function (): void {
    enablePush();
    $this->patient->updatePushSubscription(PUSH_ENDPOINT, 'public-key', 'auth-token', 'aes128gcm');

    $doctor = User::factory()->doctor()->create();
    $appointment = Appointment::factory()->awaitingReview()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => $this->patient->id,
    ]);
    $payment = Payment::factory()->create([
        'appointment_id' => $appointment->id,
        'status' => PaymentStatus::Submitted,
        'receipt_path' => 'receipts/slip.jpg',
    ]);

    $pushed = [];
    $webPush = Mockery::mock(WebPush::class);
    $webPush->shouldReceive('queueNotification')
        ->once()
        ->andReturnUsing(function (SubscriptionInterface $subscription, string $payload, array $options) use (&$pushed): void {
            $pushed = [
                'endpoint' => $subscription->getEndpoint(),
                'payload' => json_decode($payload, true),
                'options' => $options,
            ];
        });
    $webPush->shouldReceive('flush')->once()->andReturnUsing(fn (): Generator => yield from []);
    $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => $webPush);

    $this->actingAsPortal($doctor)
        ->postJson(route('api.v1.payments.verify', $payment))
        ->assertOk();

    $stored = $this->patient->notifications()->sole();

    expect($pushed['endpoint'])->toBe(PUSH_ENDPOINT)
        ->and($pushed['payload'])->toBe([
            'data' => [
                'id' => $stored->id,
                'kind' => NotificationKind::AppointmentConfirmed->value,
                'details' => ['appointment_id' => $appointment->id],
            ],
        ])
        ->and($pushed['options'])->toBe(['TTL' => 259200]);
});

it('does not let a failing push service break the request that caused it', function (): void {
    enablePush();
    $this->patient->updatePushSubscription(PUSH_ENDPOINT, 'public-key', 'auth-token', 'aes128gcm');

    $doctor = User::factory()->doctor()->create();
    $appointment = Appointment::factory()->awaitingReview()->create([
        'doctor_id' => $doctor->id,
        'patient_id' => $this->patient->id,
    ]);
    $payment = Payment::factory()->create([
        'appointment_id' => $appointment->id,
        'status' => PaymentStatus::Submitted,
        'receipt_path' => 'receipts/slip.jpg',
    ]);

    $webPush = Mockery::mock(WebPush::class);
    $webPush->shouldReceive('queueNotification')->andThrow(new RuntimeException('Push service is down.'));
    $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => $webPush);

    $this->actingAsPortal($doctor)
        ->postJson(route('api.v1.payments.verify', $payment))
        ->assertOk();

    expect($this->patient->notifications()->count())->toBe(1);
});
