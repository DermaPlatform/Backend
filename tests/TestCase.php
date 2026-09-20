<?php

namespace Tests;

use App\Enums\Portal;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a user holding a token from the portal their role signs
     * in through.
     *
     * Plain actingAs() puts a user on the guard with no access token at all,
     * which the ability middleware rejects outright. Going through Sanctum
     * mirrors how every real client reaches the API, and lets a test override
     * the portal to prove a token from the wrong application is turned away.
     */
    protected function actingAsPortal(User $user, ?Portal $portal = null): static
    {
        Sanctum::actingAs($user, [($portal ?? Portal::forRole($user->role))->ability()]);

        return $this;
    }
}
