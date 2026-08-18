<?php

it('returns a json body for an unknown api route', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertNotFound()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonStructure(['message']);
});

it('returns validation errors keyed by field name', function () {
    $this->postJson(route('api.v1.auth.register'), ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'phone', 'password']]);
});

it('throttles the auth endpoints per ip and sends a retry after header', function () {
    foreach (range(1, 20) as $attempt) {
        $this->postJson(route('api.v1.auth.password.reset'), [])->assertStatus(422);
    }

    $this->postJson(route('api.v1.auth.password.reset'), [])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});
