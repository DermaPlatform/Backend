<?php

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * US-008 — treatment category management by the admin.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

it('blocks guests, patients and doctors from category management', function (string $state, int $status): void {
    $category = ServiceCategory::factory()->create();

    if ($state === 'guest') {
        $this->getJson(route('api.v1.admin.categories.index'))->assertStatus($status);

        return;
    }

    $this->actingAs(User::factory()->{$state}()->create())
        ->getJson(route('api.v1.admin.categories.index'))
        ->assertStatus($status);

    expect($category)->not->toBeNull();
})->with([
    'guest' => ['guest', 401],
    'patient' => ['patient', 403],
    'doctor' => ['doctor', 403],
]);

it('lists categories with a service count', function (): void {
    $category = ServiceCategory::factory()->create(['name' => 'Medical']);
    Service::factory()->count(3)->for($category, 'category')->create();

    $this->actingAs($this->admin)
        ->getJson(route('api.v1.admin.categories.index'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Medical')
        ->assertJsonPath('data.0.services_count', 3);
});

it('creates a category', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.categories.store'), ['name' => 'Laser Treatments'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Laser Treatments');

    $this->assertDatabaseHas('service_categories', ['name' => 'Laser Treatments']);
});

it('rejects a duplicate category name', function (): void {
    ServiceCategory::factory()->create(['name' => 'Cosmetic']);

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.categories.store'), ['name' => 'Cosmetic'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('requires a name when creating a category', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.categories.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('reuses the name of a soft deleted category', function (): void {
    ServiceCategory::factory()->trashed()->create(['name' => 'Retired']);

    $this->actingAs($this->admin)
        ->postJson(route('api.v1.admin.categories.store'), ['name' => 'Retired'])
        ->assertCreated();
});

it('updates a category', function (): void {
    $category = ServiceCategory::factory()->create(['name' => 'Old']);

    $this->actingAs($this->admin)
        ->putJson(route('api.v1.admin.categories.update', $category), ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');

    expect($category->fresh()->name)->toBe('New');
});

it('lets a category keep its own name on update', function (): void {
    $category = ServiceCategory::factory()->create(['name' => 'Unchanged']);

    $this->actingAs($this->admin)
        ->putJson(route('api.v1.admin.categories.update', $category), ['name' => 'Unchanged'])
        ->assertOk();
});

it('soft deletes an empty category', function (): void {
    $category = ServiceCategory::factory()->create();

    $this->actingAs($this->admin)
        ->deleteJson(route('api.v1.admin.categories.destroy', $category))
        ->assertOk();

    $this->assertSoftDeleted($category);
});

it('refuses to delete a category that still has services', function (): void {
    $category = ServiceCategory::factory()->create();
    Service::factory()->for($category, 'category')->create();

    $this->actingAs($this->admin)
        ->deleteJson(route('api.v1.admin.categories.destroy', $category))
        ->assertStatus(409);

    $this->assertNotSoftDeleted($category);
});

/**
 * Regression guard: the original schema cascaded on delete, so removing a
 * category wiped every service it owned straight out of the database.
 */
it('never destroys services when a category is deleted', function (): void {
    $category = ServiceCategory::factory()->create();
    $service = Service::factory()->for($category, 'category')->create();

    $category->delete();

    expect(Service::withTrashed()->find($service->id))->not->toBeNull();
    $this->assertDatabaseHas('services', ['id' => $service->id]);
});

/**
 * The database itself must refuse a hard delete that would orphan services.
 * Before the fix this cascaded and silently destroyed the service rows.
 */
it('refuses at the database level to hard delete a category that owns services', function (): void {
    $category = ServiceCategory::factory()->create();
    $service = Service::factory()->for($category, 'category')->create();

    expect(fn () => $category->forceDelete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('services', ['id' => $service->id]);
    $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
});
