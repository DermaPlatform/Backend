<?php

use App\Models\User;

it('returns the authenticated user from the me endpoint', function () {
    $user = User::factory()->doctor()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', 'doctor')
        ->assertJsonPath('data.specialty', $user->specialty);
});

it('hides the specialty for non doctors', function () {
    $user = User::factory()->patient()->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson(route('api.v1.auth.me'))
        ->assertOk();

    expect($response->json('data'))->not->toHaveKey('specialty');
});

it('rejects a request with no token', function () {
    $this->getJson(route('api.v1.auth.me'))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('returns json instead of redirecting when the client sends no accept header', function () {
    $response = $this->get(route('api.v1.auth.me'));

    $response->assertUnauthorized()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('rejects an invalid token', function () {
    $this->withToken('1|totally-made-up-token')
        ->getJson(route('api.v1.auth.me'))
        ->assertUnauthorized();
});

it('revokes only the current token on logout', function () {
    $user = User::factory()->create();
    $currentToken = $user->createToken('phone')->plainTextToken;
    $user->createToken('tablet');

    $this->withToken($currentToken)
        ->postJson(route('api.v1.auth.logout'))
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    expect($user->tokens()->count())->toBe(1);
    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'tablet']);
});

it('cannot reuse a token after logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;

    $this->withToken($token)->postJson(route('api.v1.auth.logout'))->assertOk();

    // The guard memoizes the resolved user for the lifetime of the application
    // instance, which a real second request would not share.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.auth.me'))->assertUnauthorized();
});

it('revokes every token on logout all', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;
    $user->createToken('tablet');
    $user->createToken('laptop');

    $this->withToken($token)
        ->postJson(route('api.v1.auth.logout-all'))
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('requires authentication to log out', function () {
    $this->postJson(route('api.v1.auth.logout'))->assertUnauthorized();
    $this->postJson(route('api.v1.auth.logout-all'))->assertUnauthorized();
});

it('does not let one user act with another users token', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $token = $owner->createToken('phone')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $owner->id);

    expect($other->tokens()->count())->toBe(0);
});
