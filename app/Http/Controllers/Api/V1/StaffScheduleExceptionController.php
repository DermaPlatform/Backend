<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreScheduleExceptionRequest;
use App\Http\Resources\Api\V1\ScheduleExceptionResource;
use App\Models\ScheduleException;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Days and hours a doctor is away from their usual schedule.
 */
class StaffScheduleExceptionController extends StaffController
{
    public function __construct(private readonly ScheduleService $schedules) {}

    /**
     * Today's and upcoming exceptions. Past ones no longer affect anything.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $doctor = $this->doctorFor($request);

        return ScheduleExceptionResource::collection(
            ScheduleException::query()
                ->where('doctor_id', $doctor->id)
                ->whereDate('date', '>=', CarbonImmutable::now(config('nadara.clinic_timezone'))->toDateString())
                ->orderBy('date')
                ->orderBy('starts_at')
                ->get()
        );
    }

    public function store(StoreScheduleExceptionRequest $request): JsonResponse
    {
        $doctor = $this->doctorFor($request);

        $stranded = $this->schedules->strandedByException(
            $doctor->id,
            $request->string('date')->value(),
            $request->startsAt(),
            $request->endsAt(),
        );

        /*
         * A 409 rather than a 422: the request is well formed, it is the
         * calendar that is in the way.
         */
        if ($stranded > 0) {
            return response()->json([
                'message' => $stranded.' upcoming appointment(s) fall in this time off. Cancel or move them first.',
                'stranded_appointments' => $stranded,
            ], 409);
        }

        $exception = ScheduleException::create([
            'doctor_id' => $doctor->id,
            'date' => $request->string('date')->value(),
            'starts_at' => $request->startsAt(),
            'ends_at' => $request->endsAt(),
            'reason' => $request->string('reason')->value(),
        ]);

        return response()->json([
            'message' => 'Time off added successfully.',
            'data' => new ScheduleExceptionResource($exception),
        ], 201);
    }

    public function destroy(Request $request, ScheduleException $exception): JsonResponse
    {
        $this->ensureWithinScope($request, $exception->doctor_id);

        $exception->delete();

        return response()->json([
            'message' => 'Time off removed successfully.',
            'data' => new ScheduleExceptionResource($exception),
        ]);
    }
}
