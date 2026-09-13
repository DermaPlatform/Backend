<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCategoryRequest;
use App\Http\Requests\Api\V1\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Treatment category management for the admin portal (US-008).
 */
class AdminCategoryController extends Controller
{
    /**
     * List every category, including ones whose services are deactivated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = ServiceCategory::query()
            ->withCount('services')
            ->when(
                $request->filled('q'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->value().'%')
            )
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ServiceCategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = ServiceCategory::create($request->validated());

        return response()->json([
            'message' => 'Category created successfully.',
            'data' => new ServiceCategoryResource($category),
        ], 201);
    }

    public function show(ServiceCategory $category): ServiceCategoryResource
    {
        return new ServiceCategoryResource($category->loadCount('services')->load('services'));
    }

    public function update(UpdateCategoryRequest $request, ServiceCategory $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'message' => 'Category updated successfully.',
            'data' => new ServiceCategoryResource($category),
        ]);
    }

    /**
     * Soft delete a category. Categories still holding services are refused so
     * the catalog never ends up with services pointing at a missing parent.
     */
    public function destroy(ServiceCategory $category): JsonResponse
    {
        if ($category->services()->exists()) {
            return response()->json([
                'message' => 'This category still has services. Move or delete them first.',
            ], 409);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
            'data' => new ServiceCategoryResource($category),
        ]);
    }
}
