<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionStanding;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExtendSubscriptionRequest;
use App\Http\Resources\Api\V1\StaffAccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Doctor subscriptions as the platform admin tracks them (US-028).
 *
 * There is no billing here on purpose. Money is collected by transfer or
 * over WhatsApp, and the admin records the result by moving the end date.
 */
class AdminSubscriptionController extends Controller
{
    /**
     * Every doctor with their subscription, soonest to run out first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(SubscriptionStanding::class)],
        ]);

        $standing = $request->enum('status', SubscriptionStanding::class);

        $doctors = User::query()
            ->where('users.role', UserRole::Doctor)
            ->with('doctorProfile')
            ->when(
                $standing !== null,
                fn ($query) => $query->whereHas('doctorProfile', fn ($profile) => $profile->inStanding($standing))
            )
            ->join('doctor_profiles', 'doctor_profiles.user_id', '=', 'users.id')
            ->select('users.*')
            ->orderByRaw('doctor_profiles.subscription_ends_at is null')
            ->orderBy('doctor_profiles.subscription_ends_at')
            ->orderBy('users.name')
            ->paginate($this->perPage($request));

        return StaffAccountResource::collection($doctors);
    }

    /**
     * Move the end date after the doctor has paid, or prolong their trial.
     *
     * This also brings back a doctor who had dropped out: a new end date in
     * the future puts them in the catalog again on the next request.
     */
    public function extend(ExtendSubscriptionRequest $request, User $doctor): JsonResponse
    {
        abort_unless($doctor->role === UserRole::Doctor, 404);

        $doctor->doctorProfile()->update([
            'subscription_status' => $request->status(),
            'subscription_ends_at' => $request->endsAt(),
        ]);

        return response()->json([
            'message' => 'Subscription extended successfully.',
            'data' => new StaffAccountResource($doctor->load('doctorProfile')),
        ]);
    }
}
