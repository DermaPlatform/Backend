<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('sends a reset link to a known email', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('points the reset link at the frontend application', function () {
    Notification::fake();
    config(['app.frontend_url' => 'https://app.nadara.test']);
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        expect($url)->toStartWith('https://app.nadara.test/reset-password?')
            ->and($url)->toContain('token='.$notification->token)
            ->and($url)->toContain(urlencode($user->email));

        return true;
    });
});

it('does not disclose whether an email is registered', function () {
    Notification::fake();

    $known = User::factory()->create();

    $knownResponse = $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $known->email]);
    $unknownResponse = $this->postJson(route('api.v1.auth.password.forgot'), ['email' => 'ghost@example.com']);

    $knownResponse->assertOk();
    $unknownResponse->assertOk();
    expect($unknownResponse->json('message'))->toBe($knownResponse->json('message'));
});

it('validates the email on forgot password', function () {
    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('resets the password with a valid token', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->postJson(route('api.v1.auth.password.reset'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        return true;
    });

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

it('lets the user log in with the new password afterwards', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->postJson(route('api.v1.auth.password.reset'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        return true;
    });

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'new-password-123',
    ])->assertOk();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(422);
});

it('revokes all existing tokens after a password reset', function () {
    Notification::fake();
    $user = User::factory()->create();
    $staleToken = $user->createToken('phone')->plainTextToken;

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->postJson(route('api.v1.auth.password.reset'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        return true;
    });

    expect($user->tokens()->count())->toBe(0);
    $this->withToken($staleToken)->getJson(route('api.v1.auth.me'))->assertUnauthorized();
});

it('rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.reset'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertStatus(422);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('rejects a reset token that belongs to another email', function () {
    Notification::fake();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($otherUser) {
        $this->postJson(route('api.v1.auth.password.reset'), [
            'token' => $notification->token,
            'email' => $otherUser->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        return true;
    });

    expect(Hash::check('password', $otherUser->fresh()->password))->toBeTrue();
});

it('cannot reuse a reset token twice', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.password.forgot'), ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $payload = [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ];

        $this->postJson(route('api.v1.auth.password.reset'), $payload)->assertOk();
        $this->postJson(route('api.v1.auth.password.reset'), $payload)->assertStatus(422);

        return true;
    });
});

it('validates the reset payload', function () {
    $this->postJson(route('api.v1.auth.password.reset'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'email', 'password']);
});
