<?php

use App\Models\User;

it('logs in with valid credentials and issues a token', function () {
    $user = User::factory()->create(['email' => 'sara@example.com']);

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'sara@example.com',
        'password' => 'password',
        'device_name' => 'pixel-9',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.token_type', 'Bearer');

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'pixel-9',
    ]);
});

it('accepts a login email in any casing', function () {
    User::factory()->create(['email' => 'sara@example.com']);

    $this->postJson(route('api.v1.auth.login'), [
        'email' => ' SARA@Example.com ',
        'password' => 'password',
    ])->assertOk();
});

it('defaults the token name when no device name is given', function () {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $this->assertDatabaseHas('personal_access_tokens', ['name' => 'api']);
});

it('rejects a wrong password without leaking which field failed', function () {
    $user = User::factory()->create();

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');

    expect($response->json('errors.email.0'))->toBe(__('auth.failed'));
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('returns the same error for an unknown email as for a wrong password', function () {
    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'nobody@example.com',
        'password' => 'whatever',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.email.0'))->toBe(__('auth.failed'));
});

it('locks out after five failed attempts for the same email', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);
});

it('does not lock out a correct login after fewer than five failures', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $attempt) {
        $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();
});

it('validates required login fields', function () {
    $this->postJson(route('api.v1.auth.login'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});
