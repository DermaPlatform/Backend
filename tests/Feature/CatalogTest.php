<?php

use App\Models\Service;
use App\Models\ServiceCategory;

/**
 * US-005 — public dermatology services catalog.
 */
it('lists categories with their active services', function (): void {
    $category = ServiceCategory::factory()->create(['name' => 'Laser Treatments']);
    Service::factory()->for($category, 'category')->create(['name' => 'Laser Peel']);

    $response = $this->getJson(route('api.v1.categories.index'));

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Laser Treatments')
        ->assertJsonPath('data.0.services.0.name', 'Laser Peel')
        ->assertJsonPath('data.0.services_count', 1)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('hides deactivated services from the category listing', function (): void {
    $category = ServiceCategory::factory()->create();
    Service::factory()->for($category, 'category')->create(['name' => 'Visible']);
    Service::factory()->for($category, 'category')->inactive()->create(['name' => 'Hidden']);

    $this->getJson(route('api.v1.categories.index'))
        ->assertOk()
        ->assertJsonPath('data.0.services_count', 1)
        ->assertJsonPath('data.0.services.0.name', 'Visible');
});

it('lists only active services', function (): void {
    Service::factory()->create(['name' => 'Active Service']);
    Service::factory()->inactive()->create(['name' => 'Inactive Service']);

    $this->getJson(route('api.v1.services.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Active Service');
});

it('excludes soft deleted services from the catalog', function (): void {
    Service::factory()->create(['name' => 'Live Service']);
    Service::factory()->trashed()->create(['name' => 'Deleted Service']);

    $this->getJson(route('api.v1.services.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Live Service');
});

it('filters services by category', function (): void {
    $laser = ServiceCategory::factory()->create();
    $facial = ServiceCategory::factory()->create();
    Service::factory()->for($laser, 'category')->create(['name' => 'Laser Session']);
    Service::factory()->for($facial, 'category')->create(['name' => 'Deep Facial']);

    $this->getJson(route('api.v1.services.index', ['category_id' => $laser->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Laser Session');
});

it('searches services by name', function (): void {
    Service::factory()->create(['name' => 'Acne Treatment']);
    Service::factory()->create(['name' => 'Botox Session']);

    $this->getJson(route('api.v1.services.index', ['q' => 'Acne']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Acne Treatment');
});

it('paginates the service listing and caps the page size', function (): void {
    Service::factory()->count(30)->create();

    $this->getJson(route('api.v1.services.index', ['per_page' => 5]))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.total', 30);

    $this->getJson(route('api.v1.services.index', ['per_page' => 9999]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', config('nadara.max_per_page'));
});

it('shows a single active service with its category', function (): void {
    $category = ServiceCategory::factory()->create(['name' => 'Cosmetic']);
    $service = Service::factory()->for($category, 'category')->create(['name' => 'Chemical Peel']);

    $this->getJson(route('api.v1.services.show', $service))
        ->assertOk()
        ->assertJsonPath('data.name', 'Chemical Peel')
        ->assertJsonPath('data.category.name', 'Cosmetic');
});

it('returns 404 for a deactivated service', function (): void {
    $service = Service::factory()->inactive()->create();

    $this->getJson(route('api.v1.services.show', $service))->assertNotFound();
});

it('returns 404 for a soft deleted service', function (): void {
    $service = Service::factory()->trashed()->create();

    $this->getJson(route('api.v1.services.show', $service->id))->assertNotFound();
});

it('exposes the catalog without authentication', function (): void {
    $this->getJson(route('api.v1.categories.index'))->assertOk();
    $this->getJson(route('api.v1.services.index'))->assertOk();
});
