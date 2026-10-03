<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Resources\Api\V1\StaffPatientResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The clinic's patient list (US-016).
 */
class StaffPatientController extends StaffController
{
    /**
     * Patients of the caller's clinic, searchable by name or phone.
     *
     * A clinic's patients are the people who have booked with its doctor,
     * not everyone on the platform: one doctor has no business browsing
     * another's. The admin sees every patient.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $doctorId = $this->doctorScope($request);

        $ofThisClinic = fn (Builder $appointments) => $appointments
            ->when($doctorId !== null, fn (Builder $query) => $query->where('doctor_id', $doctorId));

        $patients = User::query()
            ->where('role', UserRole::Patient)
            ->when($doctorId !== null, fn (Builder $query) => $query->whereHas('patientAppointments', $ofThisClinic))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('q')->value().'%';

                $query->where(fn (Builder $match) => $match
                    ->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->withCount(['patientAppointments as appointments_count' => $ofThisClinic])
            ->withMax(['patientAppointments as last_appointment_at' => $ofThisClinic], 'start_at')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return StaffPatientResource::collection($patients);
    }
}
