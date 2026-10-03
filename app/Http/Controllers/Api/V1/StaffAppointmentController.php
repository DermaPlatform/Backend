<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Requests\Api\V1\CancelAppointmentByClinicRequest;
use App\Http\Requests\Api\V1\ChangeAppointmentDurationRequest;
use App\Http\Requests\Api\V1\StorePhoneAppointmentRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CancellationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What the clinic does to its own calendar: book for a caller (US-026),
 * cancel with a reason (US-013) and resize an appointment (US-015).
 *
 * Reading the calendar is not here. GET /appointments already scopes itself
 * to the caller's clinic and takes the day, week and status filters.
 */
class StaffAppointmentController extends StaffController
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly CancellationService $cancellation,
    ) {}

    /**
     * Book for a patient who called. The appointment is confirmed at once
     * and settled as cash.
     */
    public function store(StorePhoneAppointmentRequest $request): JsonResponse
    {
        $doctor = $this->doctorFor($request);

        /*
         * One transaction around both steps, so a slot that turns out to be
         * taken does not leave behind an account for a patient who never
         * got an appointment.
         */
        $appointment = DB::transaction(fn (): Appointment => $this->booking->bookByPhone(
            staff: $request->user(),
            patient: $this->patientFor($request),
            doctor: $doctor,
            service: Service::findOrFail($request->integer('service_id')),
            startAt: CarbonImmutable::parse($request->string('start_at')->value()),
        ));

        return response()->json([
            'message' => 'Appointment booked and confirmed.',
            'data' => new AppointmentResource($appointment->load(['patient', 'doctor', 'service', 'payment'])),
        ], 201);
    }

    public function cancel(CancelAppointmentByClinicRequest $request, Appointment $appointment): JsonResponse
    {
        $cancelled = $this->cancellation->cancelByClinic(
            $appointment,
            $request->user(),
            $request->string('reason')->value(),
        );

        return response()->json([
            'message' => $cancelled->refund_required
                ? 'Appointment cancelled. The patient has paid and is owed a refund.'
                : 'Appointment cancelled.',
            'data' => new AppointmentResource($cancelled->load(['patient', 'doctor', 'service', 'payment'])),
        ]);
    }

    public function updateDuration(ChangeAppointmentDurationRequest $request, Appointment $appointment): JsonResponse
    {
        $resized = $this->booking->changeDuration(
            $appointment,
            $request->user(),
            $request->integer('duration_minutes'),
        );

        return response()->json([
            'message' => 'Appointment duration updated.',
            'data' => new AppointmentResource($resized->load(['patient', 'doctor', 'service', 'payment'])),
        ]);
    }

    /**
     * The caller's account, found by phone or opened now.
     *
     * A new account gets a password nobody knows. It exists so the visit has
     * a patient to hang off, not so the caller can sign in.
     *
     * @throws ValidationException
     */
    private function patientFor(StorePhoneAppointmentRequest $request): User
    {
        $phone = $request->string('patient_phone')->value();

        $existing = User::where('phone', $phone)->first();

        if ($existing === null) {
            return User::create([
                'name' => $request->string('patient_name')->value(),
                'phone' => $phone,
                'password' => Str::password(32),
                'role' => UserRole::Patient,
            ]);
        }

        if ($existing->role !== UserRole::Patient) {
            throw ValidationException::withMessages([
                'patient_phone' => 'This phone number belongs to a staff account.',
            ]);
        }

        return $existing;
    }
}
