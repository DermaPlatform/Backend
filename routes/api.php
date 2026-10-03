<?php

use App\Enums\Portal;
use App\Http\Controllers\Api\V1\AdminAuditLogController;
use App\Http\Controllers\Api\V1\AdminAuthController;
use App\Http\Controllers\Api\V1\AdminCategoryController;
use App\Http\Controllers\Api\V1\AdminDoctorController;
use App\Http\Controllers\Api\V1\AdminSecretaryController;
use App\Http\Controllers\Api\V1\AdminServiceController;
use App\Http\Controllers\Api\V1\AdminSubscriptionController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AppointmentReceiptController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ClinicAuthController;
use App\Http\Controllers\Api\V1\ConsultationController;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\DoctorIncomeController;
use App\Http\Controllers\Api\V1\DoctorOfferingController;
use App\Http\Controllers\Api\V1\DoctorPreferenceController;
use App\Http\Controllers\Api\V1\MedicalNoteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PatientAuthController;
use App\Http\Controllers\Api\V1\PaymentReviewController;
use App\Http\Controllers\Api\V1\PrivateFileController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\StaffAppointmentController;
use App\Http\Controllers\Api\V1\StaffPatientController;
use App\Http\Controllers\Api\V1\StaffPaymentController;
use App\Http\Controllers\Api\V1\StaffScheduleController;
use App\Http\Controllers\Api\V1\StaffScheduleExceptionController;
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

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Authentication (Sprint 1)
    |----------------------------------------------------------------------
    |
    | One door per front end. Each issues a token stamped with its portal, so
    | a session opened in the patient app cannot be replayed against the back
    | office even if the token leaks. Signing in is the only part that differs:
    | reading the session, logging out and consuming a reset token are shared.
    |
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('/register', [PatientAuthController::class, 'register'])->name('register');
            Route::post('/login', [PatientAuthController::class, 'login'])->name('login');
            Route::post('/forgot-password', [PatientAuthController::class, 'forgotPassword'])
                ->name('password.forgot');
            Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        });
    });

    /*
     * The clinic dashboard. A doctor and their secretary share it, and the
     * role on the returned user tells the dashboard which screens to draw.
     */
    Route::prefix('clinic/auth')->name('clinic.auth.')->middleware('throttle:auth')->group(function (): void {
        Route::post('/login', [ClinicAuthController::class, 'login'])->name('login');
        Route::post('/forgot-password', [ClinicAuthController::class, 'forgotPassword'])->name('password.forgot');
    });

    /*
     * The back office. No registration and no public surface of any kind.
     */
    Route::prefix('admin/auth')->name('admin.auth.')->middleware('throttle:auth')->group(function (): void {
        Route::post('/login', [AdminAuthController::class, 'login'])->name('login');
        Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword'])->name('password.forgot');
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
    Route::get('/doctors', [DoctorController::class, 'index'])->name('doctors.index');
    Route::get('/doctors/{doctor}', [DoctorController::class, 'show'])->name('doctors.show');
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
        Route::get('/appointments/{appointment}/payment-receipt', [PrivateFileController::class, 'receipt'])
            ->name('appointments.receipt.show');

        Route::get('/consultations', [ConsultationController::class, 'index'])->name('consultations.index');
        Route::post('/consultations', [ConsultationController::class, 'store'])->name('consultations.store');
        Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])
            ->name('consultations.show');
        Route::post('/consultations/{consultation}/payment-receipt', [ConsultationController::class, 'storeReceipt'])
            ->name('consultations.receipt.store');
        Route::post('/consultations/{consultation}/messages', [ConsultationController::class, 'storeMessage'])
            ->name('consultations.messages.store');
        Route::get(
            '/consultations/{consultation}/attachments/{attachment}',
            [PrivateFileController::class, 'consultationAttachment']
        )->name('consultations.attachments.show');

        /*
         * Receipt review is staff work, so a patient token is turned away at
         * the door. Which staff member may touch which receipt stays with the
         * policy: a doctor and their secretary both belong here, but only for
         * their own patients.
         */
        Route::middleware(Portal::middlewareAllowing(Portal::Clinic, Portal::Admin))->group(function (): void {
            Route::post('/payments/{payment}/verify', [PaymentReviewController::class, 'verify'])
                ->name('payments.verify');
            Route::post('/payments/{payment}/reject', [PaymentReviewController::class, 'reject'])
                ->name('payments.reject');
        });
    });

    /*
    |----------------------------------------------------------------------
    | Clinic operations (US-013, US-014, US-015, US-016, US-026)
    |----------------------------------------------------------------------
    |
    | Shared by the doctor, their secretary and the admin. The role decides
    | which clinic each request works on; see StaffController. Reading the
    | calendar itself stays on GET /appointments, which is already scoped.
    |
    */
    Route::middleware([
        'auth:sanctum',
        Portal::middlewareAllowing(Portal::Clinic, Portal::Admin),
        'role:doctor,secretary,admin',
    ])
        ->prefix('staff')
        ->name('staff.')
        ->group(function (): void {
            Route::get('/patients', [StaffPatientController::class, 'index'])->name('patients.index');
            Route::get('/payments', [StaffPaymentController::class, 'index'])->name('payments.index');

            Route::post('/appointments', [StaffAppointmentController::class, 'store'])->name('appointments.store');
            Route::post('/appointments/{appointment}/cancel', [StaffAppointmentController::class, 'cancel'])
                ->name('appointments.cancel');
            Route::patch('/appointments/{appointment}/duration', [StaffAppointmentController::class, 'updateDuration'])
                ->name('appointments.duration');
            Route::post('/appointments/{appointment}/complete', [StaffAppointmentController::class, 'complete'])
                ->name('appointments.complete');

            Route::apiResource('schedules', StaffScheduleController::class)->except('show');
            Route::apiResource('schedule-exceptions', StaffScheduleExceptionController::class)
                ->only(['index', 'store', 'destroy'])
                ->parameters(['schedule-exceptions' => 'exception']);
        });

    /*
    |----------------------------------------------------------------------
    | Doctor tools (US-020, US-027)
    |----------------------------------------------------------------------
    |
    | The doctor's own business: what they offer, whether they take new
    | work, what they earned, and their clinical notes. A secretary shares
    | the clinic portal but none of this.
    |
    */
    Route::middleware(['auth:sanctum', Portal::middlewareAllowing(Portal::Clinic), 'role:doctor'])
        ->prefix('doctor')
        ->name('doctor.')
        ->group(function (): void {
            Route::get('/services', [DoctorOfferingController::class, 'index'])->name('services.index');
            Route::put('/services/{service}', [DoctorOfferingController::class, 'update'])->name('services.update');

            Route::get('/preferences', [DoctorPreferenceController::class, 'show'])->name('preferences.show');
            Route::put('/preferences', [DoctorPreferenceController::class, 'update'])->name('preferences.update');

            Route::get('/income', DoctorIncomeController::class)->name('income');

            Route::get('/appointments/{appointment}/medical-note', [MedicalNoteController::class, 'show'])
                ->name('medical-notes.show');
            Route::put('/appointments/{appointment}/medical-note', [MedicalNoteController::class, 'update'])
                ->name('medical-notes.update');
            Route::get('/patients/{patient}/history', [MedicalNoteController::class, 'history'])
                ->name('patients.history');
        });

    /*
    |----------------------------------------------------------------------
    | In-app notifications
    |----------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    });

    /*
    |----------------------------------------------------------------------
    | Admin portal (US-007, US-008)
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', Portal::middlewareAllowing(Portal::Admin), 'role:admin'])
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

            /*
             * Staff accounts (US-025). No destroy: appointments and payments
             * point at these users, so an account is deactivated, not deleted.
             */
            Route::post('/doctors/{doctor}/toggle-active', [AdminDoctorController::class, 'toggleActive'])
                ->name('doctors.toggle-active');
            Route::apiResource('doctors', AdminDoctorController::class)->except('destroy');

            Route::get('/subscriptions', [AdminSubscriptionController::class, 'index'])
                ->name('subscriptions.index');
            Route::post('/doctors/{doctor}/extend-subscription', [AdminSubscriptionController::class, 'extend'])
                ->name('doctors.extend-subscription');

            Route::get('/doctors/{doctor}/secretaries', [AdminSecretaryController::class, 'index'])
                ->name('doctors.secretaries.index');
            Route::post('/doctors/{doctor}/secretaries', [AdminSecretaryController::class, 'store'])
                ->name('doctors.secretaries.store');
            Route::put('/secretaries/{secretary}', [AdminSecretaryController::class, 'update'])
                ->name('secretaries.update');
            Route::post('/secretaries/{secretary}/toggle-active', [AdminSecretaryController::class, 'toggleActive'])
                ->name('secretaries.toggle-active');

            Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])->name('audit-logs.index');
        });
});
