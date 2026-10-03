<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Portal;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

/**
 * Sign-in for one portal.
 *
 * Each portal owns its own login and forgot-password endpoints so the three
 * front ends never share a door. What they still share lives in AuthController,
 * which is portal agnostic: reading the session, logging out, and consuming a
 * reset token.
 */
abstract class PortalAuthController extends Controller
{
    /**
     * The portal this controller signs users in to.
     */
    abstract protected function portal(): Portal;

    /**
     * Exchange credentials for a token scoped to this portal.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate($this->portal());

        return response()->json([
            'message' => 'Login successful.',
            'data' => $this->authPayload($user, $request->deviceName()),
        ]);
    }

    /**
     * Email a password reset link without disclosing whether the account
     * exists, or whether it belongs to this portal.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        if ($this->belongsToPortal($request->string('email')->value())) {
            $status = Password::sendResetLink($request->validated());

            if ($status === Password::RESET_THROTTLED) {
                return response()->json([
                    'message' => __($status),
                ], 429);
            }
        }

        return response()->json([
            'message' => 'If an account matches that email address, a password reset link has been sent.',
        ]);
    }

    /**
     * Whether that address belongs to an account this portal admits, so a
     * reset request filed at the wrong door quietly sends nothing.
     */
    private function belongsToPortal(string $email): bool
    {
        $role = User::where('email', $email)->value('role');

        return $role instanceof UserRole && $this->portal()->admits($role);
    }

    /**
     * Build the user + token payload returned by login and registration.
     *
     * The token carries this portal's ability, which is what stops a session
     * opened in one application from being replayed against another.
     *
     * @return array{user: UserResource, token: string, token_type: string, expires_at: string|null}
     */
    protected function authPayload(User $user, string $deviceName): array
    {
        $token = $user->createToken(
            $deviceName,
            [$this->portal()->ability()],
            now()->addMinutes((int) config('sanctum.expiration')),
        );

        return [
            'user' => (new UserResource($user))->asOwner(),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ];
    }
}
