<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelAppointmentRequest;
use App\Http\Requests\Api\V1\ListAppointmentsRequest;
use App\Http\Requests\Api\V1\StoreAppointmentRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CancellationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Appointment booking and cancellation (US-010, US-014).
 */
class AppointmentController extends Controller
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly CancellationService $cancellation,
    ) {}

    /**
     * The caller's own appointments, scoped by the role they hold.
     */
    public function index(ListAppointmentsRequest $request): AnonymousResourceCollection
    {
        [$windowStart, $windowEnd] = $request->startWindow();

        $appointments = Appointment::query()
            ->with(['patient', 'doctor.doctorProfile', 'service', 'payment'])
            ->tap(fn ($query) => $this->scopeToViewer($query, $request->user()))
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->value())
            )
            ->when(
                $request->filled('doctor_id'),
                fn ($query) => $query->where('doctor_id', $request->integer('doctor_id'))
            )
            ->when($windowStart, fn ($query) => $query->where('start_at', '>=', $windowStart))
            ->when($windowEnd, fn ($query) => $query->where('start_at', '<', $windowEnd))
            ->orderBy('start_at', $request->filled('period') ? 'asc' : 'desc')
            ->paginate($this->perPage($request));

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $appointment = $this->booking->book(
            patient: $request->user(),
            doctor: User::findOrFail($request->integer('doctor_id')),
            service: Service::findOrFail($request->integer('service_id')),
            startAt: CarbonImmutable::parse($request->string('start_at')->value()),
        );

        return response()->json([
            'message' => 'Appointment held. Upload your payment receipt before the hold expires.',
            'data' => new AppointmentResource($appointment->load(['doctor', 'service'])),
        ], 201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return new AppointmentResource(
            $appointment->load(['patient', 'doctor', 'service', 'payment'])
        );
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $cancelled = $this->cancellation->cancel(
            $appointment,
            $request->string('reason')->value() ?: null,
        );

        return response()->json([
            'message' => 'Appointment cancelled.',
            'data' => new AppointmentResource($cancelled->load(['doctor', 'service', 'payment'])),
        ]);
    }

    /**
     * Every role reads this list, and each sees a different slice of it.
     *
     * The secretary branch guards against a null doctor_id explicitly. A bare
     * where on a null value becomes IS NULL, which would quietly return every
     * appointment whose doctor column is also null instead of none.
     *
     * @param  Builder<Appointment>  $query
     */
    private function scopeToViewer($query, User $viewer): void
    {
        match ($viewer->role) {
            UserRole::Patient => $query->where('patient_id', $viewer->id),
            UserRole::Doctor => $query->where('doctor_id', $viewer->id),
            UserRole::Secretary => $viewer->doctor_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('doctor_id', $viewer->doctor_id),
            UserRole::Admin => $query,
        };
    }
}
