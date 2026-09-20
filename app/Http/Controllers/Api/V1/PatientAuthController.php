<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Gender;
use App\Enums\Portal;
use App\Enums\UserRole;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

/**
 * The patient portal, the only one open to the public.
 */
class PatientAuthController extends PortalAuthController
{
    protected function portal(): Portal
    {
        return Portal::Patient;
    }

    /**
     * Register a new patient account and issue an access token.
     *
     * Self registration lives here rather than in the shared controller
     * because it is the one portal a visitor can join unaided. Doctors,
     * secretaries and admins are created for them.
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
}
