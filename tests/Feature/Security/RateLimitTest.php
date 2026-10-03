<?php

use App\Models\User;

/**
 * US-022 — the ceiling on requests across the whole API.
 */
beforeEach(function (): void {
    config(['nadara.rate_limit_per_minute' => 3]);
});

it('answers 429 once a visitor passes the limit', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->getJson(route('api.v1.services.index'))->assertOk();
    }

    $this->getJson(route('api.v1.services.index'))
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('counts each signed in user on their own, even from one address', function (): void {
    $first = User::factory()->patient()->create();
    $second = User::factory()->patient()->create();

    $this->actingAsPortal($first);

    for ($i = 0; $i < 3; $i++) {
        $this->getJson(route('api.v1.profile.show'))->assertOk();
    }
    $this->getJson(route('api.v1.profile.show'))->assertStatus(429);

    $this->app['auth']->forgetGuards();

    $this->actingAsPortal($second)
        ->getJson(route('api.v1.profile.show'))
        ->assertOk();
});

it('counts each user on their own when they send a real bearer token', function (): void {
    $first = User::factory()->patient()->create();
    $second = User::factory()->patient()->create();

    $firstToken = $first->createToken('web', ['portal:patient'])->plainTextToken;
    $secondToken = $second->createToken('web', ['portal:patient'])->plainTextToken;

    for ($i = 0; $i < 3; $i++) {
        $this->withToken($firstToken)->getJson(route('api.v1.profile.show'))->assertOk();
        $this->app['auth']->forgetGuards();
    }

    $this->withToken($firstToken)->getJson(route('api.v1.profile.show'))->assertStatus(429);
    $this->app['auth']->forgetGuards();

    $this->withToken($secondToken)->getJson(route('api.v1.profile.show'))->assertOk();
});
