<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServiceRequest;
use App\Http\Requests\Api\V1\UpdateServiceRequest;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dermatology service and pricing management for the admin portal
 * (US-007, US-008).
 */
class AdminServiceController extends Controller
{
    /**
     * List services, including deactivated ones, for the admin catalog screen.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $services = Service::query()
            ->with('category')
            ->when(
                $request->filled('category_id'),
                fn ($query) => $query->where('category_id', $request->integer('category_id'))
            )
            ->when(
                $request->filled('q'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->value().'%')
            )
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ServiceResource::collection($services);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = Service::create($request->validated());

        return response()->json([
            'message' => 'Service created successfully.',
            'data' => new ServiceResource($service->refresh()->load('category')),
        ], 201);
    }

    public function show(Service $service): ServiceResource
    {
        return new ServiceResource($service->load('category'));
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $service->update($request->validated());

        return response()->json([
            'message' => 'Service updated successfully.',
            'data' => new ServiceResource($service->load('category')),
        ]);
    }

    /**
     * Soft delete a service. Past appointments keep pointing at the row, which
     * is what US-008 means by protecting existing bookings.
     */
    public function destroy(Service $service): JsonResponse
    {
        $service->delete();

        return response()->json([
            'message' => 'Service deleted successfully.',
            'data' => new ServiceResource($service),
        ]);
    }

    /**
     * Flip a service in or out of the public catalog without deleting it.
     */
    public function toggleActive(Service $service): JsonResponse
    {
        $service->update(['is_active' => ! $service->is_active]);

        return response()->json([
            'message' => $service->is_active
                ? 'Service activated successfully.'
                : 'Service deactivated successfully.',
            'data' => new ServiceResource($service->load('category')),
        ]);
    }
}
