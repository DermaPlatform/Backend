<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DoctorResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public directory of doctors (US-009).
 *
 * This is the entry point of the booking journey: a patient finds a doctor
 * here, reads their prices, then asks for that doctor's availability.
 */
class DoctorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $doctors = User::query()
            ->bookableDoctors()
            ->with(['doctorProfile', 'offeredServices' => fn ($query) => $query->wherePivot('is_active', true)])
            ->when(
                $request->filled('service_id'),
                fn ($query) => $query->whereHas(
                    'offeredServices',
                    fn ($services) => $services->where('services.id', $request->integer('service_id'))
                        ->where('doctor_service.is_active', true)
                )
            )
            ->when(
                $request->filled('q'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->value().'%')
            )
            ->when(
                $request->boolean('accepts_online_consultations'),
                fn ($query) => $query->whereHas(
                    'doctorProfile',
                    fn ($profile) => $profile->where('accepts_online_consultations', true)
                )
            )
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return DoctorResource::collection($doctors);
    }

    public function show(User $doctor): DoctorResource
    {
        abort_unless($doctor->isBookableDoctor(), 404);

        return new DoctorResource(
            $doctor->load(['doctorProfile', 'offeredServices' => fn ($query) => $query->wherePivot('is_active', true)])
        );
    }
}
