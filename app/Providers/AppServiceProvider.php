<?php

namespace App\Providers;

use App\Enums\Portal;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $this->configurePasswordRules();
        $this->configurePasswordResetUrl();
        $this->configureRateLimiting();
    }

    /**
     * Apply stricter password requirements outside of local development.
     */
    private function configurePasswordRules(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(8);

            return $this->app->isProduction()
                ? $rule->mixedCase()->numbers()->uncompromised()
                : $rule;
        });
    }

    /**
     * Point the reset link at the SPA rather than a non-existent web route.
     *
     * Which SPA is decided by the account's own role, not by the endpoint the
     * request arrived at, so a doctor always lands on the clinic dashboard
     * even if somebody files the request from the wrong front end.
     */
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = $notifiable instanceof User
                ? Portal::forRole($notifiable->role)->frontendUrl()
                : rtrim((string) config('app.frontend_url'), '/');

            return $frontendUrl.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }

    /**
     * Coarse per-IP limit for unauthenticated auth endpoints. Credential
     * stuffing is additionally throttled per email inside LoginRequest.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));
    }
}
