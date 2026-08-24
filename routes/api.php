<?php

use App\Http\Controllers\Api\V1\AdminCategoryController;
use App\Http\Controllers\Api\V1\AdminServiceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| Everything the platform exposes lives under v1. Sprint 2 originally shipped
| under a separate v2 prefix, but adding new resources is not a breaking change
| for existing clients, so the catalog, profile and admin routes were folded
| back in here alongside auth.
|
*/
Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Authentication (Sprint 1)
    |----------------------------------------------------------------------
    */
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

    /*
    |----------------------------------------------------------------------
    | Public catalog (US-005)
    |----------------------------------------------------------------------
    */
    Route::get('/categories', [CatalogController::class, 'categories'])->name('categories.index');
    Route::get('/services', [CatalogController::class, 'services'])->name('services.index');
    Route::get('/services/{service}', [CatalogController::class, 'show'])->name('services.show');

    /*
    |----------------------------------------------------------------------
    | Patient profile (US-006)
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    });

    /*
    |----------------------------------------------------------------------
    | Admin portal (US-007, US-008)
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'role:admin'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function (): void {
            Route::apiResource('categories', AdminCategoryController::class);

            Route::post('/services/{service}/toggle-active', [AdminServiceController::class, 'toggleActive'])
                ->name('services.toggle-active');
            Route::apiResource('services', AdminServiceController::class);
        });
});
