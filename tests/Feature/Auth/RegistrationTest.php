<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function validRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sara Ahmad',
        'email' => 'sara@example.com',
        'phone' => '+966500000001',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'iphone-15',
    ], $overrides);
}

it('registers a patient and returns a bearer token', function () {
    $response = $this->postJson(route('api.v1.auth.register'), validRegistrationPayload());

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'sara@example.com')
        ->assertJsonPath('data.user.role', UserRole::Patient->value)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['message', 'data' => ['user' => ['id', 'name', 'email', 'phone', 'role'], 'token']]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('users', [
        'email' => 'sara@example.com',
        'role' => UserRole::Patient->value,
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

it('stores the password hashed exactly once', function () {
    $this->postJson(route('api.v1.auth.register'), validRegistrationPayload())->assertCreated();

    $user = User::firstWhere('email', 'sara@example.com');

    expect($user->password)->not->toBe('password123')
        ->and(Hash::check('password123', $user->password))->toBeTrue();
});

it('never lets a caller choose a privileged role', function () {
    $this->postJson(route('api.v1.auth.register'), validRegistrationPayload([
        'role' => UserRole::Admin->value,
        'specialty' => 'Cardiology',
    ]))->assertCreated();

    expect(User::firstWhere('email', 'sara@example.com')->role)->toBe(UserRole::Patient);
});

it('normalizes the email to lowercase', function () {
    $this->postJson(route('api.v1.auth.register'), validRegistrationPayload([
        'email' => '  SARA@Example.COM ',
    ]))->assertCreated();

    $this->assertDatabaseHas('users', ['email' => 'sara@example.com']);
});

it('rejects a duplicate email regardless of casing', function () {
    User::factory()->create(['email' => 'sara@example.com']);

    $this->postJson(route('api.v1.auth.register'), validRegistrationPayload([
        'email' => 'SARA@EXAMPLE.COM',
    ]))->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects a duplicate phone number', function () {
    User::factory()->create(['phone' => '+966500000001']);

    $this->postJson(route('api.v1.auth.register'), validRegistrationPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('rejects an unconfirmed password', function () {
    $this->postJson(route('api.v1.auth.register'), validRegistrationPayload([
        'password_confirmation' => 'different-password',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
});

it('validates required fields', function () {
    $this->postJson(route('api.v1.auth.register'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'phone', 'password']);
});

it('rejects a password shorter than eight characters', function () {
    $this->postJson(route('api.v1.auth.register'), validRegistrationPayload([
        'password' => 'short1',
        'password_confirmation' => 'short1',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
});

it('never exposes the password in the response', function () {
    $response = $this->postJson(route('api.v1.auth.register'), validRegistrationPayload());

    expect($response->json('data.user'))->not->toHaveKey('password');
});
