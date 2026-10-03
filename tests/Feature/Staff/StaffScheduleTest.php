<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\ScheduleException;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The clinic edits its own working hours and time off.
 */
beforeEach(function (): void {
    $this->timezone = config('nadara.clinic_timezone');

    // A Monday.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 08:00', $this->timezone));

    $this->doctor = User::factory()->doctor()->create();
    $this->secretary = User::factory()->secretary($this->doctor)->create();
    $this->otherDoctor = User::factory()->doctor()->create();
    $this->admin = User::factory()->admin()->create();

    // The Sunday after, 10:00.
    $this->sunday = CarbonImmutable::parse('2026-09-20 10:00', $this->timezone);

    $this->shift = DoctorSchedule::factory()->create([
        'doctor_id' => $this->doctor->id,
        'weekday' => 0,
        'starts_at' => '09:00:00',
        'ends_at' => '13:00:00',
        'slot_minutes' => 30,
    ]);
});

function shiftPayload(array $overrides = []): array
{
    return [
        'weekday' => 2,
        'starts_at' => '09:00',
        'ends_at' => '14:00',
        'slot_minutes' => 30,
        ...$overrides,
    ];
}

function bookedOnSunday(array $overrides = []): Appointment
{
    return Appointment::factory()->approved()->create([
        'doctor_id' => test()->doctor->id,
        'start_at' => test()->sunday,
        'end_at' => test()->sunday->addMinutes(30),
        ...$overrides,
    ]);
}

it('lists the shifts of the caller\'s doctor only', function (string $who): void {
    DoctorSchedule::factory()->create(['doctor_id' => $this->otherDoctor->id]);

    $this->actingAsPortal($this->{$who})
        ->getJson(route('api.v1.staff.schedules.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->shift->id)
        ->assertJsonPath('data.0.starts_at', '09:00')
        ->assertJsonPath('data.0.ends_at', '13:00');
})->with(['doctor', 'secretary']);

it('makes the admin name the doctor', function (): void {
    $this->actingAsPortal($this->admin)
        ->getJson(route('api.v1.staff.schedules.index'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');

    $this->getJson(route('api.v1.staff.schedules.index', ['doctor_id' => $this->doctor->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson(route('api.v1.staff.schedules.store'), shiftPayload(['doctor_id' => $this->otherDoctor->id]))
        ->assertCreated()
        ->assertJsonPath('data.doctor_id', $this->otherDoctor->id);
});

it('adds a shift that patients can then book', function (): void {
    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.schedules.store'), shiftPayload())
        ->assertCreated()
        ->assertJsonPath('data.doctor_id', $this->doctor->id)
        ->assertJsonPath('data.weekday', 2)
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('doctor_schedules', [
        'doctor_id' => $this->doctor->id,
        'weekday' => 2,
        'starts_at' => '09:00:00',
        'ends_at' => '14:00:00',
    ]);

    // Tuesday 2026-09-15.
    $this->getJson(route('api.v1.doctors.availability', ['doctor' => $this->doctor, 'date' => '2026-09-15']))
        ->assertOk()
        ->assertJsonCount(10, 'data');
});

it('validates a shift', function (): void {
    $this->actingAsPortal($this->doctor)
        ->postJson(route('api.v1.staff.schedules.store'), shiftPayload([
            'weekday' => 7,
            'starts_at' => '14:00',
            'ends_at' => '09:00',
            'slot_minutes' => 1,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['weekday', 'ends_at', 'slot_minutes']);
});

it('refuses a shift that overlaps another on the same day', function (): void {
    $this->actingAsPortal($this->doctor);

    $this->postJson(route('api.v1.staff.schedules.store'), shiftPayload([
        'weekday' => 0,
        'starts_at' => '12:00',
        'ends_at' => '16:00',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');

    // Back to back is fine, and so is an inactive draft.
    $this->postJson(route('api.v1.staff.schedules.store'), shiftPayload([
        'weekday' => 0,
        'starts_at' => '13:00',
        'ends_at' => '16:00',
    ]))->assertCreated();

    $this->postJson(route('api.v1.staff.schedules.store'), shiftPayload([
        'weekday' => 0,
        'starts_at' => '10:00',
        'ends_at' => '11:00',
        'is_active' => false,
    ]))->assertCreated();
});

it('updates a shift without tripping over itself', function (): void {
    $this->actingAsPortal($this->secretary)
        ->putJson(route('api.v1.staff.schedules.update', $this->shift), shiftPayload([
            'weekday' => 0,
            'starts_at' => '08:00',
            'ends_at' => '14:00',
            'slot_minutes' => 20,
        ]))
        ->assertOk()
        ->assertJsonPath('data.starts_at', '08:00')
        ->assertJsonPath('data.slot_minutes', 20);
});

it('stops another clinic from touching a shift', function (): void {
    $outsider = User::factory()->secretary($this->otherDoctor)->create();

    foreach ([$this->otherDoctor, $outsider] as $user) {
        $this->actingAsPortal($user);

        $this->putJson(route('api.v1.staff.schedules.update', $this->shift), shiftPayload())->assertForbidden();
        $this->deleteJson(route('api.v1.staff.schedules.destroy', $this->shift))->assertForbidden();
    }

    $this->assertDatabaseHas('doctor_schedules', ['id' => $this->shift->id, 'weekday' => 0]);
});

it('refuses to remove hours that patients are booked into', function (): void {
    bookedOnSunday();

    $this->actingAsPortal($this->secretary);

    $this->deleteJson(route('api.v1.staff.schedules.destroy', $this->shift))
        ->assertStatus(409)
        ->assertJsonPath('stranded_appointments', 1);

    // Shrinking the shift past the 10:00 booking, or switching it off.
    $this->putJson(route('api.v1.staff.schedules.update', $this->shift), shiftPayload([
        'weekday' => 0,
        'starts_at' => '11:00',
        'ends_at' => '13:00',
    ]))->assertStatus(409);

    $this->putJson(route('api.v1.staff.schedules.update', $this->shift), shiftPayload([
        'weekday' => 0,
        'starts_at' => '09:00',
        'ends_at' => '13:00',
        'is_active' => false,
    ]))->assertStatus(409);

    // A change that still covers 10:00 goes through.
    $this->putJson(route('api.v1.staff.schedules.update', $this->shift), shiftPayload([
        'weekday' => 0,
        'starts_at' => '10:00',
        'ends_at' => '12:00',
    ]))->assertOk();
});

it('removes a shift once nobody is booked into it', function (): void {
    bookedOnSunday(['status' => AppointmentStatus::Cancelled]);
    bookedOnSunday(['start_at' => $this->sunday->subWeeks(2), 'end_at' => $this->sunday->subWeeks(2)->addMinutes(30)]);

    $this->actingAsPortal($this->doctor)
        ->deleteJson(route('api.v1.staff.schedules.destroy', $this->shift))
        ->assertOk();

    $this->assertDatabaseMissing('doctor_schedules', ['id' => $this->shift->id]);
});

it('adds a day off and a partial closure', function (): void {
    $this->actingAsPortal($this->secretary)
        ->postJson(route('api.v1.staff.schedule-exceptions.store'), [
            'date' => '2026-09-20',
            'reason' => 'مؤتمر',
        ])
        ->assertCreated()
        ->assertJsonPath('data.full_day', true)
        ->assertJsonPath('data.starts_at', null)
        ->assertJsonPath('data.doctor_id', $this->doctor->id);

    $this->getJson(route('api.v1.doctors.availability', ['doctor' => $this->doctor, 'date' => '2026-09-20']))
        ->assertOk()
        ->assertJsonMissing(['available' => true]);

    $this->postJson(route('api.v1.staff.schedule-exceptions.store'), [
        'date' => '2026-09-27',
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'reason' => 'اجتماع',
    ])
        ->assertCreated()
        ->assertJsonPath('data.full_day', false)
        ->assertJsonPath('data.starts_at', '09:00');
});

it('validates time off', function (): void {
    $this->actingAsPortal($this->doctor);

    $this->postJson(route('api.v1.staff.schedule-exceptions.store'), [
        'date' => '2026-09-01',
        'starts_at' => '09:00',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date', 'ends_at', 'reason']);

    $this->postJson(route('api.v1.staff.schedule-exceptions.store'), [
        'date' => '2026-09-20',
        'starts_at' => '12:00',
        'ends_at' => '09:00',
        'reason' => 'Backwards',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ends_at');
});

it('refuses time off over booked patients', function (): void {
    bookedOnSunday();

    $this->actingAsPortal($this->secretary);

    $this->postJson(route('api.v1.staff.schedule-exceptions.store'), ['date' => '2026-09-20', 'reason' => 'Off'])
        ->assertStatus(409)
        ->assertJsonPath('stranded_appointments', 1);

    $this->postJson(route('api.v1.staff.schedule-exceptions.store'), [
        'date' => '2026-09-20',
        'starts_at' => '09:30',
        'ends_at' => '10:15',
        'reason' => 'Off',
    ])->assertStatus(409);

    // A window that ends exactly as the appointment starts does not touch it.
    $this->postJson(route('api.v1.staff.schedule-exceptions.store'), [
        'date' => '2026-09-20',
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'reason' => 'Off',
    ])->assertCreated();
});

it('lists upcoming time off and removes it, within the clinic only', function (): void {
    $upcoming = ScheduleException::factory()->fullDay()->create([
        'doctor_id' => $this->doctor->id,
        'date' => '2026-09-25',
    ]);
    ScheduleException::factory()->create(['doctor_id' => $this->doctor->id, 'date' => '2026-09-01']);
    ScheduleException::factory()->create(['doctor_id' => $this->otherDoctor->id, 'date' => '2026-09-25']);

    $this->actingAsPortal($this->otherDoctor)
        ->deleteJson(route('api.v1.staff.schedule-exceptions.destroy', $upcoming))
        ->assertForbidden();

    $this->actingAsPortal($this->secretary)
        ->getJson(route('api.v1.staff.schedule-exceptions.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$upcoming->id]);

    $this->deleteJson(route('api.v1.staff.schedule-exceptions.destroy', $upcoming))->assertOk();

    $this->assertDatabaseMissing('schedule_exceptions', ['id' => $upcoming->id]);
});
