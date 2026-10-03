<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreDoctorScheduleRequest;
use App\Http\Resources\Api\V1\DoctorScheduleResource;
use App\Models\DoctorSchedule;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * A doctor's weekly working hours, edited by the clinic itself.
 *
 * Each row is one shift on one weekday. Availability is cut from these rows,
 * so a change here is live for patients on the next request.
 */
class StaffScheduleController extends StaffController
{
    public function __construct(private readonly ScheduleService $schedules) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $doctor = $this->doctorFor($request);

        return DoctorScheduleResource::collection(
            DoctorSchedule::query()
                ->where('doctor_id', $doctor->id)
                ->orderBy('weekday')
                ->orderBy('starts_at')
                ->get()
        );
    }

    public function store(StoreDoctorScheduleRequest $request): JsonResponse
    {
        $doctor = $this->doctorFor($request);
        $shift = $request->shift();

        $this->assertNoOverlap($doctor->id, $shift);

        $schedule = DoctorSchedule::create([...$shift, 'doctor_id' => $doctor->id]);

        return response()->json([
            'message' => 'Shift added successfully.',
            'data' => new DoctorScheduleResource($schedule),
        ], 201);
    }

    /**
     * Replace a shift. The whole shift is sent, as on create: its fields
     * only make sense together.
     */
    public function update(StoreDoctorScheduleRequest $request, DoctorSchedule $schedule): JsonResponse
    {
        $this->ensureWithinScope($request, $schedule->doctor_id);

        $shift = $request->shift();

        $this->assertNoOverlap($schedule->doctor_id, $shift, $schedule);

        if ($response = $this->refuseIfPatientsAreBooked($schedule, $shift)) {
            return $response;
        }

        $schedule->update($shift);

        return response()->json([
            'message' => 'Shift updated successfully.',
            'data' => new DoctorScheduleResource($schedule),
        ]);
    }

    public function destroy(Request $request, DoctorSchedule $schedule): JsonResponse
    {
        $this->ensureWithinScope($request, $schedule->doctor_id);

        if ($response = $this->refuseIfPatientsAreBooked($schedule, null)) {
            return $response;
        }

        $schedule->delete();

        return response()->json([
            'message' => 'Shift removed successfully.',
            'data' => new DoctorScheduleResource($schedule),
        ]);
    }

    /**
     * @param  array{weekday: int, starts_at: string, ends_at: string, is_active: bool}  $shift
     *
     * @throws ValidationException
     */
    private function assertNoOverlap(int $doctorId, array $shift, ?DoctorSchedule $except = null): void
    {
        if (! $shift['is_active']) {
            return;
        }

        $overlaps = $this->schedules->overlapsAnotherShift(
            $doctorId,
            $shift['weekday'],
            $shift['starts_at'],
            $shift['ends_at'],
            $except,
        );

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_at' => 'This shift overlaps another shift on the same day.',
            ]);
        }
    }

    /**
     * A 409 rather than a 422: the request is well formed, it is the
     * calendar that is in the way, and only cancelling or moving those
     * appointments will clear it.
     *
     * @param  array{weekday: int, starts_at: string, ends_at: string, is_active: bool}|null  $replacement
     */
    private function refuseIfPatientsAreBooked(DoctorSchedule $schedule, ?array $replacement): ?JsonResponse
    {
        $stranded = $this->schedules->strandedByShiftChange($schedule, $replacement);

        if ($stranded === 0) {
            return null;
        }

        return response()->json([
            'message' => $stranded.' upcoming appointment(s) fall in the hours being removed. Cancel or move them first.',
            'stranded_appointments' => $stranded,
        ], 409);
    }
}
