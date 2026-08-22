<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreCategoryRequest;
use App\Http\Requests\Api\V2\StoreServiceRequest;
use App\Http\Requests\Api\V2\UpdateCategoryRequest;
use App\Http\Requests\Api\V2\UpdateServiceRequest;
use App\Http\Resources\Api\V2\ServiceCategoryResource;
use App\Http\Resources\Api\V2\ServiceResource;
use App\Models\Service; // <--- أضفنا هذا السطر المهم جداً
use App\Models\ServiceCategory;
use Illuminate\Http\Request;

class AdminServiceController extends Controller
{
    // عرض جميع التصنيفات
    public function categories()
    {
        $categories = ServiceCategory::with('services')->get();
        return ServiceCategoryResource::collection($categories);
    }

    // إنشاء تصنيف جديد
    public function storeCategory(StoreCategoryRequest $request)
    {
        $category = ServiceCategory::create($request->validated());
        return new ServiceCategoryResource($category);
    }

    // تحديث تصنيف
    public function updateCategory(UpdateCategoryRequest $request, ServiceCategory $category)
    {
        $category->update($request->validated());
        return new ServiceCategoryResource($category);
    }

    // حذف تصنيف (Soft Delete)
    public function destroyCategory(ServiceCategory $category)
    {
        $category->delete();
        return response()->json([
            'message' => 'Category deleted successfully.',
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'deleted_at' => $category->deleted_at,
            ]
        ]);
    }

    // إنشاء خدمة جديدة
    public function storeService(StoreServiceRequest $request)
    {
        $service = Service::create($request->validated());
        return new ServiceResource($service);
    }

    // تحديث خدمة
    public function updateService(UpdateServiceRequest $request, Service $service)
    {
        $service->update($request->validated());
        return new ServiceResource($service);
    }

    // حذف خدمة (Soft Delete)
    public function destroyService(Service $service)
    {
        $service->delete();
        return response()->json([
            'message' => 'Service deleted successfully.',
            'data' => [
                'id' => $service->id,
                'name' => $service->name,
                'price' => $service->price,
                'deleted_at' => $service->deleted_at,
            ]
        ]);
    }
}