<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\ServiceCategoryResource;
use App\Http\Resources\Api\V2\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    // عرض جميع التصنيفات مع خدماتها
    public function index()
    {
        $categories = ServiceCategory::with('services')->get();
        return ServiceCategoryResource::collection($categories);
    }   

    // عرض جميع الخدمات
    public function services()
    {
        $services = Service::with('category')->get();
        return ServiceResource::collection($services);
    }   

    // (أضفنا هذه) لعرض تفاصيل خدمة واحدة مع تصنيفها
    public function show(Service $service)
    {
        $service->load('category');
        return new ServiceResource($service);
    }
}