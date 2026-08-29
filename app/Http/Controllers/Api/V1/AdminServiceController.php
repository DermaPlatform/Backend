<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServiceRequest;
use App\Http\Requests\Api\V1\UpdateServiceImageRequest;
use App\Http\Requests\Api\V1\UpdateServiceRequest;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        $attributes = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            $attributes['image_path'] = $this->storeImage($request->file('image'));
        }

        $service = Service::create($attributes);

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
     * Upload or replace the service image. This is a POST rather than part of
     * `update` because PHP does not parse multipart bodies on PUT requests.
     */
    public function updateImage(UpdateServiceImageRequest $request, Service $service): JsonResponse
    {
        $previousPath = $service->image_path;

        $service->update(['image_path' => $this->storeImage($request->file('image'))]);

        if ($previousPath !== null) {
            Storage::disk(config('nadara.uploads.disk'))->delete($previousPath);
        }

        return response()->json([
            'message' => 'Service image updated successfully.',
            'data' => new ServiceResource($service->load('category')),
        ]);
    }

    /**
     * Remove the service image and delete the stored file.
     */
    public function destroyImage(Service $service): JsonResponse
    {
        $service->clearImage();

        return response()->json([
            'message' => 'Service image removed successfully.',
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

    /**
     * Persist an uploaded image on the public disk and return its stored path.
     */
    private function storeImage(UploadedFile $image): string
    {
        return $image->store(
            config('nadara.uploads.service_image_directory'),
            config('nadara.uploads.disk')
        );
    }
}
