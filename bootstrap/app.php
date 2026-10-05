<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * This is a token based API with no server rendered login page. Without
         * this, Laravel's default guest redirect resolves route('login') and
         * blows up with a 500 whenever a client omits the JSON accept header.
         */
        $middleware->redirectGuestsTo(fn (): ?string => null);

        /*
         * Global rather than on the api group, so a response rendered from an
         * exception (401, 403, 404, 422) carries the headers as well.
         */
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureRole::class,

            /*
             * Sanctum stamps every token with the portal that issued it.
             * `abilities` demands all of the listed ones, `ability` any of
             * them, which is what a route shared by two portals needs.
             */
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
