<?php

use App\Enums\Portal;
use App\Enums\SubscriptionStatus;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Each front end signs in through a door of its own, and the token it hands
 * back only opens that front end.
 */
function login(Portal $portal, User $user, string $password = 'password'): TestResponse
{
    $route = match ($portal) {
        Portal::Patient => 'api.v1.auth.login',
        Portal::Clinic => 'api.v1.clinic.auth.login',
        Portal::Admin => 'api.v1.admin.auth.login',
    };

    return test()->postJson(route($route), [
        'email' => $user->email,
        'password' => $password,
    ]);
}

it('signs a role in through its own portal', function (Portal $portal, string $factoryState): void {
    $doctor = User::factory()->doctor()->create();

    $user = $factoryState === 'secretary'
        ? User::factory()->secretary($doctor)->create()
        : User::factory()->{$factoryState}()->create();

    login($portal, $user)
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);

    expect($user->tokens()->sole()->abilities)->toBe([$portal->ability()]);
})->with([
    'patient' => [Portal::Patient, 'patient'],
    'doctor' => [Portal::Clinic, 'doctor'],
    'secretary' => [Portal::Clinic, 'secretary'],
    'admin' => [Portal::Admin, 'admin'],
]);

it('turns a correct password away at the wrong portal', function (Portal $portal, string $factoryState): void {
    $user = User::factory()->{$factoryState}()->create();

    $response = login($portal, $user);

    $response->assertStatus(422);
    expect($response->json('errors.email.0'))->toBe(__('auth.failed'));
    expect($user->tokens()->count())->toBe(0);
})->with([
    'patient at the clinic door' => [Portal::Clinic, 'patient'],
    'patient at the back office' => [Portal::Admin, 'patient'],
    'doctor at the patient door' => [Portal::Patient, 'doctor'],
    'doctor at the back office' => [Portal::Admin, 'doctor'],
    'admin at the patient door' => [Portal::Patient, 'admin'],
    'admin at the clinic door' => [Portal::Clinic, 'admin'],
]);

it('reads the same for a wrong portal as for a wrong password', function (): void {
    $doctor = User::factory()->doctor()->create();

    $wrongPortal = login(Portal::Patient, $doctor);
    $wrongPassword = login(Portal::Clinic, $doctor, 'not-the-password');

    expect($wrongPortal->json('errors.email.0'))->toBe($wrongPassword->json('errors.email.0'));
});

it('refuses a deactivated account even with the right password', function (): void {
    $doctor = User::factory()->doctor()->create(['is_active' => false]);

    $response = login(Portal::Clinic, $doctor);

    $response->assertStatus(422);
    expect($response->json('errors.email.0'))->toBe(LoginRequest::INACTIVE_MESSAGE);
    expect($doctor->tokens()->count())->toBe(0);
});

it('still lets a doctor whose subscription lapsed sign in to renew', function (): void {
    $doctor = User::factory()->doctor()->create();
    $doctor->doctorProfile->update([
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_ends_at' => now()->subDay(),
    ]);

    login(Portal::Clinic, $doctor)->assertOk();
});

it('refuses a secretary who is attached to no doctor', function (): void {
    $secretary = User::factory()->secretary(User::factory()->doctor()->create())->create();
    $secretary->update(['doctor_id' => null]);

    $response = login(Portal::Clinic, $secretary);

    $response->assertStatus(422);
    expect($response->json('errors.email.0'))->toBe(__('auth.failed'));
});

it('does not let failures at one portal lock a user out of another', function (): void {
    $doctor = User::factory()->doctor()->create();

    foreach (range(1, 5) as $attempt) {
        login(Portal::Patient, $doctor, 'wrong-password')->assertStatus(422);
    }

    login(Portal::Patient, $doctor, 'wrong-password')->assertStatus(429);
    login(Portal::Clinic, $doctor)->assertOk();
});

it('stamps a registration token with the patient portal', function (): void {
    $response = $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Sara Odeh',
        'email' => 'sara@example.com',
        'phone' => '+970599123456',
        'birth_date' => '1995-04-12',
        'gender' => 'female',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
    ])->assertCreated();

    $user = User::where('email', 'sara@example.com')->sole();

    expect($user->tokens()->sole()->abilities)->toBe([Portal::Patient->ability()]);
    expect($response->json('data.token'))->not->toBeEmpty();
});

it('refuses a token from another portal on the back office', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAsPortal($admin, Portal::Patient)
        ->getJson(route('api.v1.admin.categories.index'))
        ->assertForbidden();

    $this->actingAsPortal($admin)
        ->getJson(route('api.v1.admin.categories.index'))
        ->assertOk();
});

it('refuses a patient token on payment review before the policy is consulted', function (): void {
    $payment = Payment::factory()->create();

    $this->actingAsPortal(User::factory()->patient()->create())
        ->postJson(route('api.v1.payments.verify', $payment))
        ->assertForbidden();
});

it('sends no reset link when the address belongs to another portal', function (): void {
    Notification::fake();
    $doctor = User::factory()->doctor()->create();

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $doctor->email])->assertOk();

    Notification::assertNothingSent();

    $this->postJson(route('api.v1.clinic.auth.password.forgot'), ['email' => $doctor->email])->assertOk();

    Notification::assertSentTo($doctor, ResetPassword::class);
});

it('points a reset link at the front end of the account owner portal', function (): void {
    Notification::fake();
    config(['nadara.portals.clinic.frontend_url' => 'https://clinic.nadara.test']);
    $doctor = User::factory()->doctor()->create();

    $this->postJson(route('api.v1.clinic.auth.password.forgot'), ['email' => $doctor->email])->assertOk();

    Notification::assertSentTo($doctor, ResetPassword::class, function (ResetPassword $notification) use ($doctor) {
        expect($notification->toMail($doctor)->actionUrl)
            ->toStartWith('https://clinic.nadara.test/reset-password?');

        return true;
    });
});
