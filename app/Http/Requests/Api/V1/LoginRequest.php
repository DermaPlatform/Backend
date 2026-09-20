<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Portal;
use App\Enums\UserRole;
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

    /**
     * Shown to the owner of a deactivated account, who has already proven the
     * password and so learns nothing new about whether the account exists.
     */
    public const INACTIVE_MESSAGE = 'This account has been deactivated. Please contact the clinic.';

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
     * A wrong password, an unknown email and an account belonging to another
     * portal all fail identically. Answering "that account exists but signs in
     * elsewhere" would tell an attacker which portal to go attack.
     *
     * @throws ValidationException
     */
    public function authenticate(Portal $portal): User
    {
        $this->ensureIsNotRateLimited($portal);

        /** @var User|null $user */
        $user = User::where('email', $this->string('email')->value())->first();

        if (! $this->opensPortal($user, $portal)) {
            RateLimiter::hit($this->throttleKey($portal), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($portal));

        /*
         * Checked only once the password is known to be correct, so the
         * message reaches the account's owner rather than anyone probing
         * addresses. A lapsed doctor is deliberately not stopped here: they
         * have to get in to renew, and the subscription is enforced per action.
         */
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => self::INACTIVE_MESSAGE,
            ]);
        }

        return $user;
    }

    /**
     * Whether these credentials open this portal.
     *
     * A secretary with no doctor attached fails as a bad credential rather
     * than as an inactive account: the record is misconfigured, and letting it
     * through would scope every clinic query to a null doctor.
     */
    private function opensPortal(?User $user, Portal $portal): bool
    {
        if (! $user || ! Hash::check($this->string('password')->value(), $user->password)) {
            return false;
        }

        if (! $portal->admits($user->role)) {
            return false;
        }

        return $user->role !== UserRole::Secretary || $user->doctor_id !== null;
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
    protected function ensureIsNotRateLimited(Portal $portal): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($portal), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($portal));

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ])->status(429);
    }

    /**
     * Rate limiting key scoped to the submitted email, the client IP and the
     * portal, so hammering one portal cannot lock a user out of another.
     */
    protected function throttleKey(Portal $portal): string
    {
        return Str::transliterate(
            Str::lower($this->string('email')->value()).'|'.$this->ip().'|'.$portal->value
        );
    }
}
