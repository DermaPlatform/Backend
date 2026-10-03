<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Exceptions\BookingNotAllowedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MedicalNoteResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The doctor's clinical notes (US-020).
 *
 * One note per visit, written by the doctor who saw the patient and read by
 * nobody else: not the secretary, not another doctor, not the admin.
 */
class MedicalNoteController extends Controller
{
    public function show(Appointment $appointment): JsonResponse
    {
        Gate::authorize('annotate', $appointment);

        $note = $appointment->medicalNote;

        return response()->json([
            'data' => $note === null ? null : new MedicalNoteResource($note),
        ]);
    }

    /**
     * Write the note for a visit, or revise it.
     *
     * @throws BookingNotAllowedException
     */
    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        Gate::authorize('annotate', $appointment);

        $request->validate([
            'notes' => ['required', 'string', 'max:10000'],
        ]);

        /*
         * A note describes a visit that is happening or has happened. An
         * unpaid hold or a cancelled booking never became one.
         */
        if (! in_array($appointment->status, [AppointmentStatus::Approved, AppointmentStatus::Completed], true)) {
            throw new BookingNotAllowedException('Notes can only be written for a confirmed appointment.', 'status');
        }

        $note = $appointment->medicalNote()->updateOrCreate([], [
            'patient_id' => $appointment->patient_id,
            'doctor_id' => $appointment->doctor_id,
            'notes' => $request->string('notes')->value(),
        ]);

        return response()->json([
            'message' => 'Medical note saved successfully.',
            'data' => new MedicalNoteResource($note),
        ]);
    }

    /**
     * A patient's past visits to the signed in doctor, newest first, each
     * with its note. This is what the doctor opens before the patient walks
     * in.
     */
    public function history(Request $request, User $patient): JsonResponse
    {
        $doctor = $request->user();

        $visits = Appointment::query()
            ->where('doctor_id', $doctor->id)
            ->where('patient_id', $patient->id)
            ->whereIn('status', [AppointmentStatus::Approved, AppointmentStatus::Completed])
            ->with(['service', 'medicalNote'])
            ->orderByDesc('start_at')
            ->get();

        /*
         * Someone who never visited this doctor is not their patient, and
         * reads the same as an id that does not exist.
         */
        abort_if($patient->role !== UserRole::Patient || $visits->isEmpty(), 404);

        return response()->json([
            'data' => [
                'patient' => new UserResource($patient),
                'visits' => $visits->map(fn (Appointment $visit): array => [
                    'appointment_id' => $visit->id,
                    'status' => $visit->status->value,
                    'start_at' => $visit->start_at->timezone(config('nadara.clinic_timezone'))->toIso8601String(),
                    'service' => $visit->service?->name,
                    'note' => $visit->medicalNote === null ? null : new MedicalNoteResource($visit->medicalNote),
                ])->all(),
            ],
        ]);
    }
}
