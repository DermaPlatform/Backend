<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V2\ProfileController;
use App\Http\Controllers\Api\V2\CatalogController;
use App\Http\Controllers\Api\V2\AdminServiceController;
use App\Http\Controllers\Api\V2\AdminCategoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes (Sprint 1)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('/register', [AuthController::class, 'register'])->name('register');
            Route::post('/login', [AuthController::class, 'login'])->name('login');
            Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');
            Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        });
    });
});

/*
|--------------------------------------------------------------------------
| API V2 Routes (Sprint 2)
|--------------------------------------------------------------------------
*/
Route::prefix('v2')->group(function () {

    // 1. Public Catalog Routes (US-005)
    Route::get('/categories', [CatalogController::class, 'index']);
    Route::get('/services', [CatalogController::class, 'services']);
    Route::get('/services/{service}', [CatalogController::class, 'show']);

    // 2. Authenticated Patient Routes (US-006)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/profile', [ProfileController::class, 'index']);
        Route::put('/profile', [ProfileController::class, 'update']);
    });

    // 3. Admin Management Routes (US-007 & US-008)
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        
        // استخدام apiResource للتصنيفات
        Route::apiResource('categories', AdminCategoryController::class);

        // الخدمات والأسعار
        Route::get('/admin-services-categories', [AdminServiceController::class, 'categories']);
        Route::post('/services', [AdminServiceController::class, 'storeService']);
        Route::put('/services/{service}', [AdminServiceController::class, 'updateService']);
        Route::delete('/services/{service}', [AdminServiceController::class, 'destroyService']);
    });
});