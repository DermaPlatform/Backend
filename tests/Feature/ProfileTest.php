<?php

use App\Enums\Gender;
use App\Models\User;

/**
 * US-006 — patient profile management.
 */
it('returns the authenticated user profile', function (): void {
    $user = User::factory()->patient()->create([
        'name' => 'Sara Ahmed',
        'phone' => '0591111111',
    ]);

    $this->actingAsPortal($user)
        ->getJson(route('api.v1.profile.show'))
        ->assertOk()
        ->assertJsonPath('data.name', 'Sara Ahmed')
        ->assertJsonPath('data.phone', '0591111111')
        ->assertJsonPath('data.role', 'patient');
});

it('never exposes the password hash', function (): void {
    $user = User::factory()->patient()->create();

    $this->actingAsPortal($user)
        ->getJson(route('api.v1.profile.show'))
        ->assertOk()
        ->assertJsonMissingPath('data.password');
});

it('rejects unauthenticated profile access', function (): void {
    $this->getJson(route('api.v1.profile.show'))->assertUnauthorized();
    $this->putJson(route('api.v1.profile.update'), ['name' => 'X'])->assertUnauthorized();
});

it('updates the name and phone', function (): void {
    $user = User::factory()->patient()->create(['name' => 'Old Name']);

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), [
            'name' => 'New Name',
            'phone' => '0599999999',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Profile updated successfully.')
        ->assertJsonPath('data.name', 'New Name');

    expect($user->fresh())
        ->name->toBe('New Name')
        ->phone->toBe('0599999999');
});

it('allows a partial update', function (): void {
    $user = User::factory()->patient()->create([
        'name' => 'Keep Me',
        'phone' => '0591234567',
    ]);

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), ['phone' => '0597654321'])
        ->assertOk();

    expect($user->fresh())
        ->name->toBe('Keep Me')
        ->phone->toBe('0597654321');
});

it('rejects a phone number already taken by another user', function (): void {
    User::factory()->patient()->create(['phone' => '0591111111']);
    $user = User::factory()->patient()->create(['phone' => '0592222222']);

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), ['phone' => '0591111111'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('allows a user to resubmit their own phone number', function (): void {
    $user = User::factory()->patient()->create(['phone' => '0593333333']);

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), [
            'name' => 'Same Phone',
            'phone' => '0593333333',
        ])
        ->assertOk();
});

it('rejects an empty name', function (): void {
    $user = User::factory()->patient()->create();

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('returns the birth date, age and gender', function (): void {
    $this->travelTo('2026-08-30');

    $user = User::factory()->patient()->create([
        'birth_date' => '1995-04-17',
        'gender' => Gender::Female,
    ]);

    $this->actingAsPortal($user)
        ->getJson(route('api.v1.profile.show'))
        ->assertOk()
        ->assertJsonPath('data.birth_date', '1995-04-17')
        ->assertJsonPath('data.age', 31)
        ->assertJsonPath('data.gender', 'female');
});

it('updates the birth date and gender', function (): void {
    $user = User::factory()->patient()->create([
        'birth_date' => '1995-04-17',
        'gender' => Gender::Female,
    ]);

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), [
            'birth_date' => '1990-01-05',
            'gender' => 'male',
        ])
        ->assertOk()
        ->assertJsonPath('data.birth_date', '1990-01-05')
        ->assertJsonPath('data.gender', 'male');

    expect($user->fresh())
        ->birth_date->toDateString()->toBe('1990-01-05')
        ->gender->toBe(Gender::Male);
});

it('rejects clearing the birth date or gender', function (string $field): void {
    $user = User::factory()->patient()->create();

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), [$field => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['birth_date', 'gender']);

it('rejects an invalid birth date or gender on update', function (array $payload, string $field): void {
    $user = User::factory()->patient()->create();

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'future birth date' => [fn () => ['birth_date' => now()->addYear()->toDateString()], 'birth_date'],
    'unknown gender' => [['gender' => 'unknown'], 'gender'],
]);

it('leaves the birth date and gender untouched on a partial update', function (): void {
    $user = User::factory()->patient()->create([
        'birth_date' => '1995-04-17',
        'gender' => Gender::Female,
    ]);

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), ['name' => 'Only The Name'])
        ->assertOk();

    expect($user->fresh())
        ->birth_date->toDateString()->toBe('1995-04-17')
        ->gender->toBe(Gender::Female);
});

it('does not allow changing the role through the profile endpoint', function (): void {
    $user = User::factory()->patient()->create();

    $this->actingAsPortal($user)
        ->putJson(route('api.v1.profile.update'), [
            'name' => 'Sneaky',
            'role' => 'admin',
        ])
        ->assertOk();

    expect($user->fresh()->role->value)->toBe('patient');
});
