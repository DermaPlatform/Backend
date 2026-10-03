<?php

use App\Enums\ConsultationStatus;
use App\Enums\NotificationKind;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Consultation;
use App\Models\DoctorSchedule;
use App\Models\Payment;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ClinicUpdate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * US-022 — every endpoint against every role.
 *
 * The matrix below is the access policy of the API written down in one
 * place. Each route names the roles that may use it; every other role must
 * be turned away, with a 401 for a visitor and a 403 for a signed in user.
 *
 * "May use" means the request gets past authentication and authorization. It
 * may still fail validation or find nothing: each request is sent with an
 * empty body against records the acting users own, so a 422 or a 404 from
 * an allowed role is fine, and a 401, a 403 or a 500 is not.
 *
 * A route missing from the matrix fails the suite, so a new endpoint cannot
 * ship without someone deciding who it is for.
 */
const EVERYONE = ['guest', 'patient', 'doctor', 'secretary', 'admin'];
const SIGNED_IN = ['patient', 'doctor', 'secretary', 'admin'];
const CLINIC = ['doctor', 'secretary', 'admin'];
const ADMIN = ['admin'];
const DOCTOR = ['doctor'];
const PATIENT = ['patient'];

function accessMatrix(): array
{
    return [
        // Signing in and out.
        'auth.register' => EVERYONE,
        'auth.login' => EVERYONE,
        'auth.password.forgot' => EVERYONE,
        'auth.password.reset' => EVERYONE,
        'clinic.auth.login' => EVERYONE,
        'clinic.auth.password.forgot' => EVERYONE,
        'admin.auth.login' => EVERYONE,
        'admin.auth.password.forgot' => EVERYONE,
        'auth.me' => SIGNED_IN,
        'auth.logout' => SIGNED_IN,
        'auth.logout-all' => SIGNED_IN,

        // The public catalog and the doctors directory.
        'categories.index' => EVERYONE,
        'services.index' => EVERYONE,
        'services.show' => EVERYONE,
        'doctors.index' => EVERYONE,
        'doctors.show' => EVERYONE,
        'doctors.availability' => EVERYONE,

        // One's own account.
        'profile.show' => SIGNED_IN,
        'profile.update' => SIGNED_IN,
        'profile.image.update' => SIGNED_IN,
        'profile.image.destroy' => SIGNED_IN,
        'notifications.index' => SIGNED_IN,
        'notifications.read' => SIGNED_IN,
        'notifications.read-all' => SIGNED_IN,

        // Appointments: everyone lists and opens their own slice; only the
        // patient books, cancels as a patient and uploads the receipt.
        'appointments.index' => SIGNED_IN,
        'appointments.show' => SIGNED_IN,
        'appointments.store' => PATIENT,
        'appointments.cancel' => PATIENT,
        'appointments.receipt.store' => PATIENT,
        'appointments.receipt.show' => SIGNED_IN,
        'payments.receipt.show' => SIGNED_IN,

        // Consultations are clinical: the secretary may list (and gets
        // nothing) but opens, reads and writes none of them.
        'consultations.index' => SIGNED_IN,
        'consultations.store' => PATIENT,
        'consultations.show' => ['patient', 'doctor', 'admin'],
        'consultations.receipt.store' => PATIENT,
        'consultations.messages.store' => ['patient', 'doctor'],
        'consultations.attachments.show' => ['patient', 'doctor', 'admin'],

        // Money is staff work.
        'payments.verify' => CLINIC,
        'payments.reject' => CLINIC,

        // Clinic operations.
        'staff.patients.index' => CLINIC,
        'staff.payments.index' => CLINIC,
        'staff.appointments.store' => CLINIC,
        'staff.appointments.cancel' => CLINIC,
        'staff.appointments.duration' => CLINIC,
        'staff.appointments.complete' => CLINIC,
        'staff.schedules.index' => CLINIC,
        'staff.schedules.store' => CLINIC,
        'staff.schedules.update' => CLINIC,
        'staff.schedules.destroy' => CLINIC,
        'staff.schedule-exceptions.index' => CLINIC,
        'staff.schedule-exceptions.store' => CLINIC,
        'staff.schedule-exceptions.destroy' => CLINIC,

        // The doctor's own business, closed even to their secretary.
        'doctor.services.index' => DOCTOR,
        'doctor.services.update' => DOCTOR,
        'doctor.preferences.show' => DOCTOR,
        'doctor.preferences.update' => DOCTOR,
        'doctor.income' => DOCTOR,
        'doctor.medical-notes.show' => DOCTOR,
        'doctor.medical-notes.update' => DOCTOR,
        'doctor.patients.history' => DOCTOR,

        // The back office.
        'admin.categories.index' => ADMIN,
        'admin.categories.store' => ADMIN,
        'admin.categories.show' => ADMIN,
        'admin.categories.update' => ADMIN,
        'admin.categories.destroy' => ADMIN,
        'admin.services.index' => ADMIN,
        'admin.services.store' => ADMIN,
        'admin.services.show' => ADMIN,
        'admin.services.update' => ADMIN,
        'admin.services.destroy' => ADMIN,
        'admin.services.toggle-active' => ADMIN,
        'admin.services.image.update' => ADMIN,
        'admin.services.image.destroy' => ADMIN,
        'admin.doctors.index' => ADMIN,
        'admin.doctors.store' => ADMIN,
        'admin.doctors.show' => ADMIN,
        'admin.doctors.update' => ADMIN,
        'admin.doctors.toggle-active' => ADMIN,
        'admin.doctors.secretaries.index' => ADMIN,
        'admin.doctors.secretaries.store' => ADMIN,
        'admin.doctors.extend-subscription' => ADMIN,
        'admin.secretaries.update' => ADMIN,
        'admin.secretaries.toggle-active' => ADMIN,
        'admin.subscriptions.index' => ADMIN,
        'admin.audit-logs.index' => ADMIN,
    ];
}

/**
 * Every named v1 route, without its shared prefix.
 *
 * @return array<string, RoutingRoute>
 */
function apiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'api.v1.'))
        ->mapWithKeys(fn (RoutingRoute $route): array => [substr($route->getName(), 7) => $route])
        ->all();
}

beforeEach(function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));
    Storage::fake(config('nadara.uploads.disk'));

    $this->doctor = User::factory()->doctor()->create();
    $this->actors = [
        'patient' => User::factory()->patient()->create(),
        'doctor' => $this->doctor,
        'secretary' => User::factory()->secretary($this->doctor)->create(),
        'admin' => User::factory()->admin()->create(),
    ];

    $service = Service::factory()->create();
    $this->doctor->offeredServices()->attach($service->id, [
        'price' => 100,
        'duration_minutes' => 30,
        'is_active' => true,
    ]);

    $appointment = Appointment::factory()->awaitingReview()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->actors['patient']->id,
        'service_id' => $service->id,
    ]);
    $receiptPath = 'receipts/slip.jpg';
    Storage::disk(config('nadara.uploads.private_disk'))->put($receiptPath, 'receipt');
    $payment = Payment::factory()->create([
        'appointment_id' => $appointment->id,
        'status' => PaymentStatus::Submitted,
        'receipt_path' => $receiptPath,
    ]);

    $consultation = Consultation::factory()->create([
        'doctor_id' => $this->doctor->id,
        'patient_id' => $this->actors['patient']->id,
        'status' => ConsultationStatus::AwaitingReply,
    ]);
    $attachment = Attachment::factory()->create([
        'attachable_type' => $consultation->getMorphClass(),
        'attachable_id' => $consultation->id,
    ]);
    Storage::disk(config('nadara.uploads.private_disk'))->put($attachment->path, 'photo');

    foreach ($this->actors as $actor) {
        $actor->notify(new ClinicUpdate(NotificationKind::AppointmentConfirmed, ['appointment_id' => $appointment->id]));
    }

    $this->parameters = [
        'service' => $service->id,
        'category' => $service->category_id,
        'doctor' => $this->doctor->id,
        'secretary' => $this->actors['secretary']->id,
        'patient' => $this->actors['patient']->id,
        'appointment' => $appointment->id,
        'payment' => $payment->id,
        'consultation' => $consultation->id,
        'attachment' => $attachment->id,
        'schedule' => DoctorSchedule::factory()->create(['doctor_id' => $this->doctor->id])->id,
        'exception' => ScheduleException::factory()->create(['doctor_id' => $this->doctor->id])->id,
    ];
});

it('lists every route in the matrix, and nothing that no longer exists', function (): void {
    $routes = array_keys(apiRoutes());
    $listed = array_keys(accessMatrix());

    expect(array_values(array_diff($routes, $listed)))->toBe([], 'Routes with no access decision')
        ->and(array_values(array_diff($listed, $routes)))->toBe([], 'Matrix entries with no route');
});

it('admits exactly the roles the matrix names', function (string $name): void {
    $route = apiRoutes()[$name];
    $allowed = accessMatrix()[$name];
    $method = collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first();

    foreach (EVERYONE as $role) {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $parameters = array_intersect_key($this->parameters, array_flip($route->parameterNames()));

        if ($role !== 'guest') {
            $this->actingAsPortal($this->actors[$role]);

            if (in_array('notification', $route->parameterNames(), true)) {
                $parameters['notification'] = $this->actors[$role]->notifications()->value('id');
            }
        } elseif (in_array('notification', $route->parameterNames(), true)) {
            $parameters['notification'] = 'none';
        }

        $status = $this->json($method, route('api.v1.'.$name, $parameters))->getStatusCode();

        $label = "{$method} {$name} as {$role} answered {$status}";

        if (in_array($role, $allowed, true)) {
            expect($status)->not->toBeIn([401, 403], $label)
                ->and($status)->toBeLessThan(500, $label);
        } else {
            expect($status)->toBe($role === 'guest' ? 401 : 403, $label);
        }
    }
})->with(fn (): array => array_keys(accessMatrix()));
