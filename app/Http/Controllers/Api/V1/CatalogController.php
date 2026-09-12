<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServiceCategoryResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public dermatology catalog (US-005). Only active services are exposed here;
 * the admin endpoints are the ones that see deactivated rows.
 */
class CatalogController extends Controller
{
    /**
     * List treatment categories with their active services.
     */
    public function categories(Request $request): AnonymousResourceCollection
    {
        $categories = ServiceCategory::query()
            ->with(['services' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->withCount(['services' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ServiceCategoryResource::collection($categories);
    }

    /**
     * List active services, filterable by category and free-text search.
     */
    public function services(Request $request): AnonymousResourceCollection
    {
        $services = Service::query()
            ->with('category')
            ->withCatalogPricing()
            ->where('is_active', true)
            ->when(
                $request->filled('category_id'),
                fn ($query) => $query->where('category_id', $request->integer('category_id'))
            )
            ->when(
                $request->filled('q'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->value().'%')
            )
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ServiceResource::collection($services);
    }

    /**
     * Show one active service with its category.
     */
    public function show(Service $service): ServiceResource
    {
        abort_unless($service->is_active, 404);

        $service->load(['category', 'bookableDoctors.doctorProfile']);

        return new ServiceResource(
            Service::query()->withCatalogPricing()->findOrFail($service->id)->setRelations($service->getRelations())
        );
    }
}
