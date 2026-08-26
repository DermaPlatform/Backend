<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Failed attempts allowed per email + IP combination before locking out.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Lockout duration in seconds once the attempt limit is reached.
     */
    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * Resolve the user matching the credentials, throttling failed attempts.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        /** @var User|null $user */
        $user = User::where('email', $this->string('email')->value())->first();

        if (! $user || ! Hash::check($this->string('password')->value(), $user->password)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * The device name used to label the issued access token.
     */
    public function deviceName(): string
    {
        $deviceName = trim((string) $this->input('device_name'));

        return $deviceName !== '' ? $deviceName : 'api';
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ])->status(429);
    }

    /**
     * Rate limiting key scoped to the submitted email and the client IP.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->value()).'|'.$this->ip());
    }
}
