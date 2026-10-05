<?php

use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;

/**
 * The trail of staff decisions: what gets written, and who may read it.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

function submittedPayment(User $doctor): Payment
{
    $appointment = Appointment::factory()->awaitingReview()->create(['doctor_id' => $doctor->id]);

    return Payment::factory()->create([
        'appointment_id' => $appointment->id,
        'status' => PaymentStatus::Submitted,
        'receipt_path' => 'receipts/slip.jpg',
    ]);
}

it('records who verified a payment', function (): void {
    $doctor = User::factory()->doctor()->create();
    $secretary = User::factory()->secretary($doctor)->create();
    $payment = submittedPayment($doctor);

    $this->actingAsPortal($secretary)
        ->postJson(route('api.v1.payments.verify', $payment))
        ->assertOk();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe(AuditAction::PaymentVerified)
        ->and($log->user_id)->toBe($secretary->id)
        ->and($log->auditable->is($payment))->toBeTrue()
        ->and($log->changes['appointment_id'])->toBe($payment->appointment_id);
});

it('records a rejected payment with its reason', function (): void {
    $doctor = User::factory()->doctor()->create();
    $payment = submittedPayment($doctor);

    $this->actingAsPortal($doctor)
        ->postJson(route('api.v1.payments.reject', $payment), ['reason' => 'المبلغ ناقص'])
        ->assertOk();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe(AuditAction::PaymentRejected)
        ->and($log->user_id)->toBe($doctor->id)
        ->and($log->changes['reason'])->toBe('المبلغ ناقص');
});

it('writes nothing when a review is refused', function (): void {
    $payment = submittedPayment(User::factory()->doctor()->create());

    $this->actingAsPortal(User::factory()->doctor()->create())
        ->postJson(route('api.v1.payments.verify', $payment))
        ->assertForbidden();

    expect(AuditLog::query()->count())->toBe(0);
});

it('records account creation without the password', function (): void {
    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.store'), [
            'name' => 'د. سامي',
            'email' => 'sami@nadara.test',
            'phone' => '+970599000111',
            'password' => 'Str0ng-Passw0rd!',
        ])
        ->assertCreated();

    $doctor = User::where('email', 'sami@nadara.test')->sole();

    $this->postJson(route('api.v1.admin.doctors.secretaries.store', $doctor), [
        'name' => 'رنا',
        'email' => 'rana@nadara.test',
        'phone' => '+970599000222',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertCreated();

    $logs = AuditLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action)->toBe(AuditAction::AccountCreated)
        ->and($logs[0]->auditable_id)->toBe($doctor->id)
        ->and($logs[0]->changes)->toBe(['role' => 'doctor', 'email' => 'sami@nadara.test'])
        ->and($logs[1]->changes['doctor_id'])->toBe($doctor->id)
        ->and(json_encode($logs->pluck('changes')))->not->toContain('Str0ng');
});

it('records deactivation, reactivation and a password reset', function (): void {
    $doctor = User::factory()->doctor()->create();
    $secretary = User::factory()->secretary($doctor)->create();

    $this->actingAsPortal($this->admin);

    $this->postJson(route('api.v1.admin.doctors.toggle-active', $doctor))->assertOk();
    $this->postJson(route('api.v1.admin.doctors.toggle-active', $doctor))->assertOk();
    $this->putJson(route('api.v1.admin.doctors.update', $doctor), ['password' => 'An0ther-Passw0rd!'])->assertOk();
    $this->putJson(route('api.v1.admin.doctors.update', $doctor), ['name' => 'No trail for a rename'])->assertOk();
    $this->postJson(route('api.v1.admin.secretaries.toggle-active', $secretary))->assertOk();
    $this->putJson(route('api.v1.admin.secretaries.update', $secretary), ['password' => 'An0ther-Passw0rd!'])->assertOk();

    expect(AuditLog::query()->orderBy('id')->get()->map(fn (AuditLog $log): array => [
        $log->action,
        $log->auditable_id,
    ])->all())->toBe([
        [AuditAction::AccountDeactivated, $doctor->id],
        [AuditAction::AccountActivated, $doctor->id],
        [AuditAction::AccountPasswordReset, $doctor->id],
        [AuditAction::AccountDeactivated, $secretary->id],
        [AuditAction::AccountPasswordReset, $secretary->id],
    ])->and(json_encode(AuditLog::query()->pluck('changes')))->not->toContain('An0ther');
});

it('records a subscription extension with before and after', function (): void {
    $doctor = User::factory()->doctor()->create();
    $doctor->doctorProfile->update(['subscription_ends_at' => null]);

    $this->actingAsPortal($this->admin)
        ->postJson(route('api.v1.admin.doctors.extend-subscription', $doctor), [
            'ends_at' => now()->addMonth()->toDateString(),
        ])
        ->assertOk();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe(AuditAction::SubscriptionExtended)
        ->and($log->auditable_id)->toBe($doctor->id)
        ->and($log->changes['from'])->toBe(['status' => 'trial', 'ends_at' => null])
        ->and($log->changes['to']['status'])->toBe('active')
        ->and($log->changes['to']['ends_at'])->not->toBeNull();
});

it('blocks everyone but the admin from the trail', function (string $state, int $status): void {
    if ($state !== 'guest') {
        $doctor = User::factory()->doctor()->create();

        $this->actingAsPortal(match ($state) {
            'doctor' => $doctor,
            'secretary' => User::factory()->secretary($doctor)->create(),
            default => User::factory()->patient()->create(),
        });
    }

    $this->getJson(route('api.v1.admin.audit-logs.index'))->assertStatus($status);
})->with([
    'guest' => ['guest', 401],
    'patient' => ['patient', 403],
    'doctor' => ['doctor', 403],
    'secretary' => ['secretary', 403],
]);

it('lists the trail newest first and filters it', function (): void {
    $doctor = User::factory()->doctor()->create(['name' => 'Amal']);

    $old = AuditLog::factory()->create([
        'user_id' => $doctor->id,
        'action' => AuditAction::PaymentVerified,
        'created_at' => now()->subDays(10),
    ]);
    $new = AuditLog::factory()->create([
        'user_id' => $this->admin->id,
        'action' => AuditAction::SubscriptionExtended,
        'changes' => ['to' => ['status' => 'active']],
    ]);

    $this->actingAsPortal($this->admin)
        ->getJson(route('api.v1.admin.audit-logs.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$new->id, $old->id])
        ->assertJsonPath('data.0.action', 'subscription.extended')
        ->assertJsonPath('data.0.actor.id', $this->admin->id)
        ->assertJsonPath('data.0.subject.type', 'user')
        ->assertJsonPath('data.0.changes.to.status', 'active')
        ->assertJsonPath('data.1.actor.name', 'Amal');

    $this->getJson(route('api.v1.admin.audit-logs.index', ['action' => 'payment.verified']))
        ->assertJsonPath('data.*.id', [$old->id]);

    $this->getJson(route('api.v1.admin.audit-logs.index', ['user_id' => $this->admin->id]))
        ->assertJsonPath('data.*.id', [$new->id]);

    $this->getJson(route('api.v1.admin.audit-logs.index', ['from' => now()->subDays(2)->toDateString()]))
        ->assertJsonPath('data.*.id', [$new->id]);

    $this->getJson(route('api.v1.admin.audit-logs.index', ['to' => now()->subDays(2)->toDateString()]))
        ->assertJsonPath('data.*.id', [$old->id]);

    $this->getJson(route('api.v1.admin.audit-logs.index', ['action' => 'nonsense']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('action');
});

it('has no route that changes the trail', function (): void {
    $log = AuditLog::factory()->create();

    $this->actingAsPortal($this->admin);

    $this->deleteJson('/api/v1/admin/audit-logs/'.$log->id)->assertNotFound();
    $this->putJson('/api/v1/admin/audit-logs/'.$log->id, [])->assertNotFound();

    $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
});
