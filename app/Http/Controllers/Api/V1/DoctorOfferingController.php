<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateDoctorOfferingRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the signed in doctor offers, and at what price.
 *
 * The platform owns the catalog of services. Each doctor decides which of
 * them they perform, what they charge and how long they give it. Appointments
 * already booked are untouched by anything here: they carry their own price
 * and end time from the moment they were made.
 */
class DoctorOfferingController extends Controller
{
    /**
     * The whole catalog, each service with the doctor's own terms or null
     * where they have not priced it yet.
     */
    public function index(Request $request): JsonResponse
    {
        $offerings = $request->user()->offeredServices()->get()->keyBy('id');

        $services = Service::query()
            ->where('is_active', true)
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service): array => $this->present($service, $offerings->get($service->id)));

        return response()->json(['data' => $services]);
    }

    /**
     * Price a service, or change its terms. Switching is_active off takes
     * the service off the doctor's page without losing the price.
     */
    public function update(UpdateDoctorOfferingRequest $request, Service $service): JsonResponse
    {
        abort_unless($service->is_active, 404);

        $doctor = $request->user();
        $existing = $doctor->offeredServices()->wherePivot('service_id', $service->id)->first();

        $terms = [
            'price' => $request->validated('price'),
            'duration_minutes' => $request->integer('duration_minutes'),
            'is_active' => $request->boolean('is_active', $existing?->pivot->is_active ?? true),
        ];

        $doctor->offeredServices()->syncWithoutDetaching([$service->id => $terms]);

        return response()->json([
            'message' => 'Service terms saved successfully.',
            'data' => $this->present(
                $service->load('category'),
                $doctor->offeredServices()->wherePivot('service_id', $service->id)->first(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Service $service, ?Service $offered): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'category' => $service->category?->name,
            'image_url' => $service->image_url,
            'default_duration_minutes' => $service->default_duration_minutes,
            'offering' => $offered === null ? null : [
                'price' => $offered->pivot->price,
                'duration_minutes' => $offered->pivot->duration_minutes,
                'is_active' => $offered->pivot->is_active,
            ],
        ];
    }
}
