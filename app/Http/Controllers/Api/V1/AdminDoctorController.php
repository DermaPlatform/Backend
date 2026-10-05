<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDoctorRequest;
use App\Http\Requests\Api\V1\UpdateDoctorRequest;
use App\Http\Resources\Api\V1\StaffAccountResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Doctor accounts as the platform admin manages them (US-025).
 *
 * Doctors never self-register: each one joins after an agreement with the
 * platform, so the admin is the only way an account comes to exist. There is
 * no delete on purpose. Appointments, payments and notes all point at the
 * doctor, so an account that should stop working is deactivated instead.
 */
class AdminDoctorController extends Controller
{
    /**
     * List every doctor, including deactivated and lapsed ones.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $doctors = User::query()
            ->where('role', UserRole::Doctor)
            ->with(['doctorProfile', 'secretaries'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->value().'%';

                $query->where(fn ($match) => $match
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return StaffAccountResource::collection($doctors);
    }

    /**
     * Create the account together with its profile. A doctor without a
     * profile row is invisible to the catalog and breaks the subscription
     * check, so the two are written in one transaction.
     */
    public function store(StoreDoctorRequest $request): JsonResponse
    {
        $doctor = DB::transaction(function () use ($request): User {
            $doctor = User::create([
                ...$request->safe()->only(['name', 'email', 'phone', 'password']),
                'role' => UserRole::Doctor,
            ]);

            /*
             * The admin typed this address after agreeing terms with the
             * doctor, which is the verification a patient gets by email.
             */
            $doctor->forceFill(['email_verified_at' => now()])->save();

            $doctor->doctorProfile()->create([
                'specialty' => $request->validated('specialty'),
                'subscription_status' => SubscriptionStatus::Trial,
                'subscription_ends_at' => now()->addDays((int) config('nadara.subscriptions.trial_days')),
            ]);

            AuditLog::record($request->user(), AuditAction::AccountCreated, $doctor, [
                'role' => UserRole::Doctor->value,
                'email' => $doctor->email,
            ]);

            return $doctor;
        });

        return response()->json([
            'message' => 'Doctor created successfully.',
            'data' => new StaffAccountResource($doctor->load(['doctorProfile', 'secretaries'])),
        ], 201);
    }

    public function show(User $doctor): StaffAccountResource
    {
        $this->ensureIsDoctor($doctor);

        return new StaffAccountResource($doctor->load(['doctorProfile', 'secretaries']));
    }

    public function update(UpdateDoctorRequest $request, User $doctor): JsonResponse
    {
        $this->ensureIsDoctor($doctor);

        DB::transaction(function () use ($request, $doctor): void {
            $doctor->update($request->safe()->only(['name', 'email', 'phone', 'password']));

            if ($request->has('specialty')) {
                $doctor->doctorProfile()->update(['specialty' => $request->validated('specialty')]);
            }

            /*
             * A password set by the admin is a reset: sessions opened with
             * the old one must not outlive it.
             */
            if ($request->has('password')) {
                $doctor->revokeAllSessions();

                AuditLog::record($request->user(), AuditAction::AccountPasswordReset, $doctor);
            }
        });

        return response()->json([
            'message' => 'Doctor updated successfully.',
            'data' => new StaffAccountResource($doctor->load(['doctorProfile', 'secretaries'])),
        ]);
    }

    /**
     * Deactivate or reactivate the account.
     *
     * Deactivating takes the doctor out of the catalog and signs them out
     * everywhere. Existing appointments are left alone: they are paid for,
     * and cancelling them is a separate decision with a refund attached.
     */
    public function toggleActive(Request $request, User $doctor): JsonResponse
    {
        $this->ensureIsDoctor($doctor);

        DB::transaction(function () use ($request, $doctor): void {
            $doctor->update(['is_active' => ! $doctor->is_active]);

            if (! $doctor->is_active) {
                $doctor->revokeAllSessions();
            }

            AuditLog::record(
                $request->user(),
                $doctor->is_active ? AuditAction::AccountActivated : AuditAction::AccountDeactivated,
                $doctor,
            );
        });

        return response()->json([
            'message' => $doctor->is_active
                ? 'Doctor activated successfully.'
                : 'Doctor deactivated successfully.',
            'data' => new StaffAccountResource($doctor->load(['doctorProfile', 'secretaries'])),
        ]);
    }

    /**
     * The {doctor} binding resolves any user id, so a patient or secretary id
     * has to read as a missing doctor rather than be edited as one.
     */
    private function ensureIsDoctor(User $user): void
    {
        abort_unless($user->role === UserRole::Doctor, 404);
    }
}
