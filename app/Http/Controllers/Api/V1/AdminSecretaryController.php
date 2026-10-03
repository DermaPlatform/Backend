<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSecretaryRequest;
use App\Http\Requests\Api\V1\UpdateSecretaryRequest;
use App\Http\Resources\Api\V1\StaffAccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Secretary accounts as the platform admin manages them (US-025).
 *
 * A secretary is always created under a doctor and never moves: the doctor is
 * taken from the URL rather than the body, so there is no request that can
 * produce a secretary with a null doctor_id, which would scope every clinic
 * query to IS NULL.
 */
class AdminSecretaryController extends Controller
{
    public function index(User $doctor): AnonymousResourceCollection
    {
        $this->ensureIsDoctor($doctor);

        return StaffAccountResource::collection($doctor->secretaries()->orderBy('name')->get());
    }

    public function store(StoreSecretaryRequest $request, User $doctor): JsonResponse
    {
        $this->ensureIsDoctor($doctor);

        $secretary = User::create([
            ...$request->validated(),
            'role' => UserRole::Secretary,
            'doctor_id' => $doctor->id,
        ]);

        $secretary->forceFill(['email_verified_at' => now()])->save();

        return response()->json([
            'message' => 'Secretary created successfully.',
            'data' => new StaffAccountResource($secretary),
        ], 201);
    }

    public function update(UpdateSecretaryRequest $request, User $secretary): JsonResponse
    {
        $this->ensureIsSecretary($secretary);

        $secretary->update($request->validated());

        if ($request->has('password')) {
            $secretary->tokens()->delete();
        }

        return response()->json([
            'message' => 'Secretary updated successfully.',
            'data' => new StaffAccountResource($secretary),
        ]);
    }

    /**
     * Deactivate or reactivate the account. Deactivating signs the secretary
     * out everywhere, since they hold the clinic's receipts and calendar.
     */
    public function toggleActive(User $secretary): JsonResponse
    {
        $this->ensureIsSecretary($secretary);

        $secretary->update(['is_active' => ! $secretary->is_active]);

        if (! $secretary->is_active) {
            $secretary->tokens()->delete();
        }

        return response()->json([
            'message' => $secretary->is_active
                ? 'Secretary activated successfully.'
                : 'Secretary deactivated successfully.',
            'data' => new StaffAccountResource($secretary),
        ]);
    }

    private function ensureIsDoctor(User $user): void
    {
        abort_unless($user->role === UserRole::Doctor, 404);
    }

    private function ensureIsSecretary(User $user): void
    {
        abort_unless($user->role === UserRole::Secretary, 404);
    }
}
