<?php

use App\Enums\Portal;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * US-025 — doctor and secretary accounts managed by the platform admin.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

function doctorPayload(array $overrides = []): array
{
    return [
        'name' => 'د. سامي الخطيب',
        'email' => 'sami@nadara.test',
        'phone' => '+970599000111',
        'password' => 'Str0ng-Passw0rd!',
        'specialty' => 'Dermatology',
        ...$overrides,
    ];
}

it('blocks everyone but the admin from staff accounts', function (string $state, int $status): void {
    if ($state === 'guest') {
        $this->getJson(route('api.v1.admin.doctors.index'))->assertStatus($status);

        return;
    }

    $user = $state === 'secretary'
        ? User::factory()->secretary(User::factory()->doctor()->create())->create()
        : User::factory()->{$state}()->create();

    $this->actingAsPortal($user)
        ->getJson(route('api.v1.admin.doctors.index'))
        ->assertStatus($status);

    $this->actingAsPortal($user)
        ->postJson(route('api.v1.admin.doctors.store'), doctorPayload())
        ->assertStatus($status);
})->with([
    'guest' => ['guest', 401],
    'patient' => ['patient', 403],
    'doctor' => ['doctor', 403],
    'secretary' => ['secretary', 403],
]);

it('creates a doctor on trial with a profile', function (): void {
    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.store'), doctorPayload(['email' => 'Sami@Nadara.test']))
        ->assertCreated()
        ->assertJsonPath('data.email', 'sami@nadara.test')
        ->assertJsonPath('data.role', 'doctor')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.specialty', 'Dermatology')
        ->assertJsonPath('data.subscription.status', 'trial')
        ->assertJsonMissingPath('data.password');

    $doctor = User::where('email', 'sami@nadara.test')->firstOrFail();

    expect($doctor->role)->toBe(UserRole::Doctor)
        ->and($doctor->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Str0ng-Passw0rd!', $doctor->password))->toBeTrue()
        ->and($doctor->doctorProfile->subscription_status)->toBe(SubscriptionStatus::Trial)
        ->and($doctor->isBookableDoctor())->toBeTrue();
});

it('lets a created doctor sign in through the clinic portal', function (): void {
    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.store'), doctorPayload())
        ->assertCreated();

    $this->app['auth']->forgetGuards();

    $this->postJson(route('api.v1.clinic.auth.login'), [
        'email' => 'sami@nadara.test',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertOk();
});

it('rejects a doctor with a taken email or phone', function (): void {
    User::factory()->create(['email' => 'sami@nadara.test', 'phone' => '+970599000111']);

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.store'), doctorPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'phone']);
});

it('requires the core fields when creating a doctor', function (): void {
    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'phone', 'password']);
});

it('lists only doctors, with their secretaries, and filters them', function (): void {
    $active = User::factory()->doctor()->create(['name' => 'Amal']);
    $inactive = User::factory()->doctor()->create(['name' => 'Basel', 'is_active' => false]);
    User::factory()->secretary($active)->create(['name' => 'Rana']);
    User::factory()->patient()->create();

    $this->actingAsPortal($this->admin)
        ->getJson(route('api.v1.admin.doctors.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $active->id)
        ->assertJsonPath('data.0.secretaries.0.name', 'Rana')
        ->assertJsonPath('data.1.id', $inactive->id);

    $this->getJson(route('api.v1.admin.doctors.index', ['is_active' => 0]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inactive->id);

    $this->getJson(route('api.v1.admin.doctors.index', ['q' => 'ama']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

it('answers 404 when the id is not a doctor', function (): void {
    $patient = User::factory()->patient()->create();

    $this->actingAsPortal($this->admin)
        ->getJson(route('api.v1.admin.doctors.show', $patient))
        ->assertNotFound();

    $this->putJson(route('api.v1.admin.doctors.update', $patient), ['name' => 'Hijacked'])
        ->assertNotFound();

    $this->postJson(route('api.v1.admin.doctors.toggle-active', $patient))
        ->assertNotFound();

    $this->postJson(route('api.v1.admin.doctors.secretaries.store', $patient), doctorPayload())
        ->assertNotFound();
});

it('updates a doctor and their specialty', function (): void {
    $doctor = User::factory()->doctor()->create();

    $this->actingAsPortal($this->admin)
        ->putJson(route('api.v1.admin.doctors.update', $doctor), [
            'name' => 'د. ليلى',
            'email' => $doctor->email,
            'specialty' => 'Cosmetic Dermatology',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'د. ليلى')
        ->assertJsonPath('data.specialty', 'Cosmetic Dermatology');
});

it('does not let an update change the role', function (): void {
    $doctor = User::factory()->doctor()->create();

    $this->actingAsPortal($this->admin)
        ->putJson(route('api.v1.admin.doctors.update', $doctor), ['role' => 'admin', 'is_active' => false])
        ->assertOk();

    expect($doctor->refresh()->role)->toBe(UserRole::Doctor)
        ->and($doctor->is_active)->toBeTrue();
});

it('signs the doctor out when the admin resets their password', function (): void {
    $doctor = User::factory()->doctor()->create();
    $doctor->createToken('clinic', [Portal::Clinic->ability()]);

    $this->actingAsPortal($this->admin)
        ->putJson(route('api.v1.admin.doctors.update', $doctor), ['password' => 'An0ther-Passw0rd!'])
        ->assertOk();

    expect($doctor->tokens()->count())->toBe(0)
        ->and(Hash::check('An0ther-Passw0rd!', $doctor->refresh()->password))->toBeTrue();
});

it('deactivates a doctor, hides them from the catalog and signs them out', function (): void {
    $doctor = User::factory()->doctor()->create();
    $doctor->createToken('clinic', [Portal::Clinic->ability()]);

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.toggle-active', $doctor))
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($doctor->tokens()->count())->toBe(0)
        ->and($doctor->refresh()->isBookableDoctor())->toBeFalse();

    $this->postJson(route('api.v1.admin.doctors.toggle-active', $doctor))
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('has no route to delete a doctor', function (): void {
    $doctor = User::factory()->doctor()->create();

    $this->actingAsPortal($this->admin)
        ->deleteJson('/api/v1/admin/doctors/'.$doctor->id)
        ->assertStatus(405);

    $this->assertDatabaseHas('users', ['id' => $doctor->id]);
});

it('creates a secretary tied to the doctor in the url', function (): void {
    $doctor = User::factory()->doctor()->create();
    $other = User::factory()->doctor()->create();

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.secretaries.store', $doctor), doctorPayload([
            'doctor_id' => $other->id,
            'role' => 'admin',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.role', 'secretary')
        ->assertJsonPath('data.doctor_id', $doctor->id)
        ->assertJsonMissingPath('data.subscription');

    $this->getJson(route('api.v1.admin.doctors.secretaries.index', $doctor))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson(route('api.v1.admin.doctors.secretaries.index', $other))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('updates and deactivates a secretary', function (): void {
    $doctor = User::factory()->doctor()->create();
    $secretary = User::factory()->secretary($doctor)->create();
    $secretary->createToken('clinic', [Portal::Clinic->ability()]);

    $this->actingAsPortal($this->admin)
        ->putJson(route('api.v1.admin.secretaries.update', $secretary), [
            'name' => 'رنا',
            'doctor_id' => User::factory()->doctor()->create()->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'رنا')
        ->assertJsonPath('data.doctor_id', $doctor->id);

    $this->postJson(route('api.v1.admin.secretaries.toggle-active', $secretary))
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($secretary->tokens()->count())->toBe(0);
});

it('answers 404 when the secretary id is not a secretary', function (): void {
    $doctor = User::factory()->doctor()->create();

    $this->actingAsPortal($this->admin)
        ->putJson(route('api.v1.admin.secretaries.update', $doctor), ['name' => 'Nope'])
        ->assertNotFound();

    $this->postJson(route('api.v1.admin.secretaries.toggle-active', $doctor))
        ->assertNotFound();
});
