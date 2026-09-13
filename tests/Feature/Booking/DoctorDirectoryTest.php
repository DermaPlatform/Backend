<?php

use App\Enums\SubscriptionStatus;
use App\Models\Service;
use App\Models\User;

/**
 * US-009 — finding a doctor. This is the first step of the booking journey:
 * without it a patient has no doctor id to ask availability for.
 */
beforeEach(function (): void {
    $this->service = Service::factory()->create(['name' => 'Acne Consultation', 'price' => 200.00]);

    $this->cheap = User::factory()->doctor()->create(['name' => 'Dr. Aya Cheap']);
    $this->pricey = User::factory()->doctor()->create(['name' => 'Dr. Basel Pricey']);

    $this->cheap->offeredServices()->attach($this->service->id, [
        'price' => 60.00, 'duration_minutes' => 30, 'is_active' => true,
    ]);

    $this->pricey->offeredServices()->attach($this->service->id, [
        'price' => 110.00, 'duration_minutes' => 45, 'is_active' => true,
    ]);
});

it('lists bookable doctors without a token', function (): void {
    $this->getJson(route('api.v1.doctors.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Dr. Aya Cheap')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('shows each doctor their own price and duration for a service', function (): void {
    $response = $this->getJson(route('api.v1.doctors.index'))->assertOk();

    expect($response->json('data.0.services.0.price'))->toBe('60.00')
        ->and($response->json('data.0.services.0.duration_minutes'))->toBe(30)
        ->and($response->json('data.1.services.0.price'))->toBe('110.00')
        ->and($response->json('data.1.services.0.duration_minutes'))->toBe(45);
});

it('hides a deactivated doctor from the directory', function (): void {
    $this->pricey->update(['is_active' => false]);

    $this->getJson(route('api.v1.doctors.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('hides a doctor whose subscription has lapsed', function (): void {
    $this->pricey->doctorProfile->update([
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_ends_at' => now()->subDay(),
    ]);

    $this->getJson(route('api.v1.doctors.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('keeps a trial doctor visible with no end date', function (): void {
    $this->pricey->doctorProfile->update([
        'subscription_status' => SubscriptionStatus::Trial,
        'subscription_ends_at' => null,
    ]);

    $this->getJson(route('api.v1.doctors.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters the directory by service', function (): void {
    $other = Service::factory()->create();
    $third = User::factory()->doctor()->create(['name' => 'Dr. Other']);
    $third->offeredServices()->attach($other->id, [
        'price' => 10.00, 'duration_minutes' => 15, 'is_active' => true,
    ]);

    $this->getJson(route('api.v1.doctors.index', ['service_id' => $this->service->id]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters the directory to doctors taking online consultations', function (): void {
    $this->cheap->doctorProfile->update(['accepts_online_consultations' => true]);

    $this->getJson(route('api.v1.doctors.index', ['accepts_online_consultations' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Dr. Aya Cheap');
});

it('answers 404 for a doctor who cannot be booked', function (): void {
    $this->cheap->update(['is_active' => false]);

    $this->getJson(route('api.v1.doctors.show', $this->cheap))->assertNotFound();
});

it('shows the catalog a starts from price taken from the cheapest doctor', function (): void {
    $this->getJson(route('api.v1.services.index'))
        ->assertOk()
        ->assertJsonPath('data.0.starting_price', '60.00')
        ->assertJsonPath('data.0.bookable_doctors_count', 2);
});

it('leaves the starts from price out while nobody has priced the service', function (): void {
    $unpriced = Service::factory()->create(['name' => 'Zzz Unpriced']);

    $response = $this->getJson(route('api.v1.services.index'))->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $unpriced->id);

    expect($row)->not->toHaveKey('starting_price')
        ->and($row['bookable_doctors_count'])->toBe(0);
});

it('ignores a lapsed doctor when computing the starts from price', function (): void {
    $this->cheap->doctorProfile->update([
        'subscription_status' => SubscriptionStatus::Expired,
        'subscription_ends_at' => now()->subDay(),
    ]);

    $this->getJson(route('api.v1.services.index'))
        ->assertOk()
        ->assertJsonPath('data.0.starting_price', '110.00')
        ->assertJsonPath('data.0.bookable_doctors_count', 1);
});

it('lists the bookable doctors on a single service page', function (): void {
    $this->getJson(route('api.v1.services.show', $this->service))
        ->assertOk()
        ->assertJsonCount(2, 'data.doctors')
        ->assertJsonPath('data.starting_price', '60.00');
});
