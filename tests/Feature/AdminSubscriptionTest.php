<?php

use App\Enums\SubscriptionStanding;
use App\Enums\SubscriptionStatus;
use App\Models\User;

/**
 * US-028 — doctor subscriptions tracked and extended by the platform admin.
 */
beforeEach(function (): void {
    $this->freezeTime();
    $this->admin = User::factory()->admin()->create();
});

function doctorWithSubscription(SubscriptionStatus $status, ?DateTimeInterface $endsAt, string $name = 'Doctor'): User
{
    $doctor = User::factory()->doctor()->create(['name' => $name]);
    $doctor->doctorProfile->update([
        'subscription_status' => $status,
        'subscription_ends_at' => $endsAt,
    ]);

    return $doctor->refresh();
}

it('works out the standing from the status and the end date', function (
    SubscriptionStatus $status,
    ?int $endsInDays,
    SubscriptionStanding $standing,
): void {
    $doctor = doctorWithSubscription($status, $endsInDays === null ? null : now()->addDays($endsInDays));

    expect($doctor->doctorProfile->subscriptionStanding())->toBe($standing)
        ->and($doctor->isBookableDoctor())->toBe($standing->takesNewWork())
        ->and(User::query()->bookableDoctors()->whereKey($doctor->id)->exists())->toBe($standing->takesNewWork())
        ->and(
            User::query()
                ->whereKey($doctor->id)
                ->whereHas('doctorProfile', fn ($profile) => $profile->inStanding($standing))
                ->exists()
        )->toBeTrue();
})->with([
    'open trial' => [SubscriptionStatus::Trial, null, SubscriptionStanding::Trial],
    'running trial' => [SubscriptionStatus::Trial, 3, SubscriptionStanding::Trial],
    'trial in grace' => [SubscriptionStatus::Trial, -3, SubscriptionStanding::Grace],
    'trial past grace' => [SubscriptionStatus::Trial, -7, SubscriptionStanding::Expired],
    'open active' => [SubscriptionStatus::Active, null, SubscriptionStanding::Active],
    'running active' => [SubscriptionStatus::Active, 20, SubscriptionStanding::Active],
    'active in grace' => [SubscriptionStatus::Active, -6, SubscriptionStanding::Grace],
    'active past grace' => [SubscriptionStatus::Active, -8, SubscriptionStanding::Expired],
    'marked expired' => [SubscriptionStatus::Expired, 5, SubscriptionStanding::Expired],
]);

it('keeps a doctor in grace bookable and drops them once it ends', function (): void {
    $doctor = doctorWithSubscription(SubscriptionStatus::Active, now()->subDays(2));

    $this->getJson(route('api.v1.doctors.show', $doctor))->assertOk();

    $this->travel(6)->days();

    $this->getJson(route('api.v1.doctors.show', $doctor))->assertNotFound();
});

it('starts a doctor created by the admin on a dated trial', function (): void {
    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.store'), [
            'name' => 'د. سامي',
            'email' => 'sami@nadara.test',
            'phone' => '+970599000111',
            'password' => 'Str0ng-Passw0rd!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.subscription.status', 'trial')
        ->assertJsonPath('data.subscription.ends_at', now()->addDays(14)->toIso8601String())
        ->assertJsonPath('data.subscription.grace_ends_at', now()->addDays(21)->toIso8601String());
});

it('blocks everyone but the admin from subscriptions', function (string $state, int $status): void {
    $doctor = User::factory()->doctor()->create();

    if ($state !== 'guest') {
        $this->actingAsPortal(
            $state === 'secretary'
                ? User::factory()->secretary($doctor)->create()
                : ($state === 'doctor' ? $doctor : User::factory()->patient()->create())
        );
    }

    $this->getJson(route('api.v1.admin.subscriptions.index'))->assertStatus($status);

    $this->postJson(route('api.v1.admin.doctors.extend-subscription', $doctor), [
        'ends_at' => now()->addMonth()->toDateString(),
    ])->assertStatus($status);
})->with([
    'guest' => ['guest', 401],
    'patient' => ['patient', 403],
    'doctor' => ['doctor', 403],
    'secretary' => ['secretary', 403],
]);

it('lists doctors soonest to run out first and filters by standing', function (): void {
    $open = doctorWithSubscription(SubscriptionStatus::Trial, null, 'Open');
    $later = doctorWithSubscription(SubscriptionStatus::Active, now()->addDays(20), 'Later');
    $grace = doctorWithSubscription(SubscriptionStatus::Active, now()->subDays(2), 'Grace');
    $lapsed = doctorWithSubscription(SubscriptionStatus::Active, now()->subDays(30), 'Lapsed');
    User::factory()->patient()->create();

    $this->actingAsPortal($this->admin)
        ->getJson(route('api.v1.admin.subscriptions.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$lapsed->id, $grace->id, $later->id, $open->id])
        ->assertJsonPath('data.0.subscription.status', 'expired')
        ->assertJsonPath('data.1.subscription.status', 'grace')
        ->assertJsonPath('data.3.subscription.ends_at', null);

    foreach (['trial' => $open, 'active' => $later, 'grace' => $grace, 'expired' => $lapsed] as $status => $doctor) {
        $this->getJson(route('api.v1.admin.subscriptions.index', ['status' => $status]))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$doctor->id]);
    }

    $this->getJson(route('api.v1.admin.subscriptions.index', ['status' => 'nonsense']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('extends a subscription and brings a lapsed doctor back', function (): void {
    $doctor = doctorWithSubscription(SubscriptionStatus::Expired, now()->subDays(40));
    $endsAt = now(config('nadara.clinic_timezone'))->addMonth();

    expect($doctor->isBookableDoctor())->toBeFalse();

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.extend-subscription', $doctor), [
            'ends_at' => $endsAt->toDateString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.subscription.status', 'active');

    $profile = $doctor->refresh()->doctorProfile;

    expect($profile->subscription_status)->toBe(SubscriptionStatus::Active)
        ->and($profile->subscription_ends_at->equalTo($endsAt->endOfDay()->startOfSecond()))->toBeTrue()
        ->and($doctor->isBookableDoctor())->toBeTrue();
});

it('can prolong a trial without marking it paid', function (): void {
    $doctor = doctorWithSubscription(SubscriptionStatus::Trial, now()->addDay());

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.extend-subscription', $doctor), [
            'ends_at' => now()->addMonths(6)->toDateString(),
            'status' => 'trial',
        ])
        ->assertOk()
        ->assertJsonPath('data.subscription.status', 'trial');
});

it('rejects a past end date, a bad status and a non doctor', function (): void {
    $doctor = User::factory()->doctor()->create();

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.extend-subscription', $doctor), [
            'ends_at' => now()->subDays(2)->toDateString(),
            'status' => 'expired',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_at', 'status']);

    $this->postJson(route('api.v1.admin.doctors.extend-subscription', $doctor), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ends_at');

    $this->postJson(route('api.v1.admin.doctors.extend-subscription', User::factory()->patient()->create()), [
        'ends_at' => now()->addMonth()->toDateString(),
    ])->assertNotFound();
});
