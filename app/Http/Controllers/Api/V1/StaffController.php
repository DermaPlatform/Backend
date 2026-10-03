<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared ground for the clinic's day to day routes under /staff.
 *
 * Three roles reach them and each works on a different slice: a doctor on
 * their own clinic, a secretary on the one doctor they work for, and the
 * admin on any clinic, optionally narrowed with doctor_id.
 */
abstract class StaffController extends Controller
{
    /**
     * The doctor this request is confined to, or null for an admin looking
     * across every clinic.
     *
     * Only the admin may name a doctor. For everyone else the doctor comes
     * from who they are, never from what they send.
     */
    protected function doctorScope(Request $request): ?int
    {
        $user = $request->user();

        $request->validate([
            'doctor_id' => [
                'nullable',
                Rule::prohibitedIf($user->role !== UserRole::Admin),
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Doctor->value),
            ],
        ]);

        return match ($user->role) {
            UserRole::Doctor => $user->id,
            /*
             * A secretary with no doctor attached cannot sign in, but a
             * record edited afterwards could still reach here. Null would
             * read as the admin's unrestricted view, so it is refused.
             */
            UserRole::Secretary => $user->doctor_id ?? abort(403),
            default => $request->filled('doctor_id') ? $request->integer('doctor_id') : null,
        };
    }

    /**
     * The doctor a write is made for. The admin has to name one: a shift or
     * a phone booking always belongs to exactly one clinic.
     *
     * @throws ValidationException
     */
    protected function doctorFor(Request $request): User
    {
        $doctorId = $this->doctorScope($request);

        if ($doctorId === null) {
            throw ValidationException::withMessages([
                'doctor_id' => 'The doctor id field is required.',
            ]);
        }

        return User::findOrFail($doctorId);
    }

    /**
     * Refuse a record that belongs to another doctor's clinic.
     */
    protected function ensureWithinScope(Request $request, int $doctorId): void
    {
        $scope = $this->doctorScope($request);

        abort_unless($scope === null || $scope === $doctorId, 403);
    }
}
