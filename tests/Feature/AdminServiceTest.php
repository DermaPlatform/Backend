<?php

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;

/**
 * US-007 / US-008 — service and pricing management by the admin.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->category = ServiceCategory::factory()->create();
});

it('blocks non-admins from service management', function (string $state, int $status): void {
    if ($state === 'guest') {
        $this->postJson(route('api.v1.admin.services.store'), [])->assertStatus($status);

        return;
    }

    $this->actingAs(User::factory()->{$state}()->create())
        ->postJson(route('api.v1.admin.services.store'), [])
        ->assertStatus($status);
})->with([
    'guest' => ['guest', 401],
    'patient' => ['patient', 403],
    'doctor' => ['doctor', 403],
]);

it('lists services including deactivated ones', function (): void {
    Service::factory()->for($this->category, 'category')->create(['name' => 'Active One']);
    Service::factory()->for($this->category, 'category')->inactive()->create(['name' => 'Inactive One']);

    $this->actingAs($this->admin)
        ->getJson(route('api.v1.admin.services.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters the admin listing by active state', function (): void {
    Service::factory()->for($this->category, 'category')->create();
    Service::factory()->for($this->category, 'category')->inactive()->create(['name' => 'Hidden']);

    $this->actingAs($this->admin)
        ->getJson(route('api.v1.admin.services.index', ['is_active' => false]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Hidden');
});

it('creates a service', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.services.store'), [
            'category_id' => $this->category->id,
            'name' => 'Acne Treatment',
            'description' => 'Assessment and plan.',
            'price' => 75.50,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Acne Treatment')
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('services', [
        'name' => 'Acne Treatment',
        'price' => 75.50,
    ]);
});

it('validates the service payload', function (array $payload, string $field): void {
    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.services.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['category_id' => 1, 'price' => 10], 'name'],
    'missing price' => [['category_id' => 1, 'name' => 'X'], 'price'],
    'negative price' => [['category_id' => 1, 'name' => 'X', 'price' => -5], 'price'],
    'unknown category' => [['category_id' => 9999, 'name' => 'X', 'price' => 10], 'category_id'],
]);

it('rejects a duplicate service name', function (): void {
    Service::factory()->for($this->category, 'category')->create(['name' => 'Chemical Peel']);

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.services.store'), [
            'category_id' => $this->category->id,
            'name' => 'Chemical Peel',
            'price' => 100,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('updates a service price and reflects it in the public catalog immediately', function (): void {
    $service = Service::factory()->for($this->category, 'category')->create(['price' => 100]);

    $this->actingAs($this->admin)
        ->putJson(route('api.v1.admin.services.update', $service), ['price' => 250])
        ->assertOk();

    $this->getJson(route('api.v1.services.show', $service))
        ->assertOk()
        ->assertJsonPath('data.price', '250.00');
});

it('toggles a service in and out of the public catalog', function (): void {
    $service = Service::factory()->for($this->category, 'category')->create();

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.services.toggle-active', $service))
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->getJson(route('api.v1.services.show', $service))->assertNotFound();

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.services.toggle-active', $service))
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    $this->getJson(route('api.v1.services.show', $service))->assertOk();
});

it('soft deletes a service and removes it from the patient catalog', function (): void {
    $service = Service::factory()->for($this->category, 'category')->create();

    $this->actingAs($this->admin)
        ->deleteJson(route('api.v1.admin.services.destroy', $service))
        ->assertOk();

    $this->assertSoftDeleted($service);
    $this->getJson(route('api.v1.services.index'))->assertOk()->assertJsonCount(0, 'data');
});

it('keeps the deleted service row on record for past bookings', function (): void {
    $service = Service::factory()->for($this->category, 'category')->create();

    $this->actingAs($this->admin)
        ->deleteJson(route('api.v1.admin.services.destroy', $service))
        ->assertOk();

    $this->assertDatabaseHas('services', ['id' => $service->id]);
    expect(Service::withTrashed()->find($service->id))->not->toBeNull();
});
