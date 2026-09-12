<?php

use App\Http\Controllers\Api\V1\AdminCategoryController;
use App\Http\Controllers\Api\V1\AdminServiceController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AppointmentReceiptController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\PaymentReviewController;
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
        Route::post('/profile/image', [ProfileController::class, 'updateImage'])->name('profile.image.update');
        Route::delete('/profile/image', [ProfileController::class, 'destroyImage'])->name('profile.image.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | Doctor availability (US-009)
    |----------------------------------------------------------------------
    |
    | Public on purpose. A visitor comparing doctors needs to see the calendar
    | before deciding whether registering is worth it.
    |
    */
    Route::get('/doctors/{doctor}/availability', AvailabilityController::class)->name('doctors.availability');

    /*
    |----------------------------------------------------------------------
    | Booking, payment and consultations (US-010, US-012, US-013, US-014)
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->name('appointments.cancel');
        Route::post('/appointments/{appointment}/payment-receipt', [AppointmentReceiptController::class, 'store'])
            ->name('appointments.receipt.store');

        Route::get('/consultations', [ConsultationController::class, 'index'])->name('consultations.index');
        Route::post('/consultations', [ConsultationController::class, 'store'])->name('consultations.store');
        Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])
            ->name('consultations.show');
        Route::post('/consultations/{consultation}/payment-receipt', [ConsultationController::class, 'storeReceipt'])
            ->name('consultations.receipt.store');
        Route::post('/consultations/{consultation}/messages', [ConsultationController::class, 'storeMessage'])
            ->name('consultations.messages.store');

        /*
         * Receipt review is gated by the policy rather than by a role in the
         * route, because a doctor and their secretary both belong here but
         * only for their own patients.
         */
        Route::post('/payments/{payment}/verify', [PaymentReviewController::class, 'verify'])
            ->name('payments.verify');
        Route::post('/payments/{payment}/reject', [PaymentReviewController::class, 'reject'])
            ->name('payments.reject');
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

            Route::post('/services/{service}/image', [AdminServiceController::class, 'updateImage'])
                ->name('services.image.update');
            Route::delete('/services/{service}/image', [AdminServiceController::class, 'destroyImage'])
                ->name('services.image.destroy');
            Route::apiResource('services', AdminServiceController::class);
        });
});
