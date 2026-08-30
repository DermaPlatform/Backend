<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register a new patient account and issue an access token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'phone' => $request->string('phone')->value(),
            'birth_date' => $request->date('birth_date'),
            'gender' => Gender::from($request->string('gender')->value()),
            'password' => $request->string('password')->value(),
            'role' => UserRole::Patient,
        ]);

        event(new Registered($user));

        return response()->json([
            'message' => 'Registration successful.',
            'data' => $this->authPayload($user, $request->deviceName()),
        ], 201);
    }

    /**
     * Exchange credentials for a new access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        return response()->json([
            'message' => 'Login successful.',
            'data' => $this->authPayload($user, $request->deviceName()),
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    /**
     * Revoke only the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Revoke every access token belonging to the user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices.',
        ]);
    }

    /**
     * Email a password reset link without disclosing whether the account exists.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->validated());

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => __($status),
            ], 429);
        }

        return response()->json([
            'message' => 'If an account matches that email address, a password reset link has been sent.',
        ]);
    }

    /**
     * Consume a reset token, set the new password and revoke existing tokens.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->brokerCredentials(),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
                'errors' => ['email' => [__($status)]],
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    /**
     * Build the user + token payload returned by register and login.
     *
     * @return array{user: UserResource, token: string, token_type: string, expires_at: string|null}
     */
    private function authPayload(User $user, string $deviceName): array
    {
        $token = $user->createToken($deviceName);

        return [
            'user' => (new UserResource($user))->asOwner(),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ];
    }
}
