<?php

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Filtering the appointment list by status, period, date range and doctor.
 */
beforeEach(function (): void {
    $this->timezone = config('nadara.clinic_timezone');

    // A Wednesday, so the Saturday to Friday week has days on both sides.
    $this->travelTo(CarbonImmutable::parse('2026-09-16 08:00', $this->timezone));

    $this->doctor = User::factory()->doctor()->create();

    $this->bookAt = function (string $clinicTime, ?User $doctor = null): Appointment {
        $startAt = CarbonImmutable::parse($clinicTime, $this->timezone);

        return Appointment::factory()->approved()->forDoctor($doctor ?? $this->doctor)->create([
            'start_at' => $startAt,
            'end_at' => $startAt->addMinutes(30),
        ]);
    };

    $this->listedIds = function (User $viewer, array $query): array {
        return $this->actingAsPortal($viewer)
            ->getJson(route('api.v1.appointments.index', [...$query, 'per_page' => 100]))
            ->assertOk()
            ->json('data.*.id');
    };
});

it('lists today on the clinic clock, not the UTC one', function (): void {
    $earlyToday = ($this->bookAt)('2026-09-16 01:00');
    $lateToday = ($this->bookAt)('2026-09-16 23:30');
    ($this->bookAt)('2026-09-15 23:30');
    ($this->bookAt)('2026-09-17 00:00');

    expect(($this->listedIds)($this->doctor, ['period' => 'today']))
        ->toBe([$earlyToday->id, $lateToday->id]);
});

it('lists tomorrow', function (): void {
    ($this->bookAt)('2026-09-16 10:00');
    $tomorrow = ($this->bookAt)('2026-09-17 10:00');
    ($this->bookAt)('2026-09-18 00:00');

    expect(($this->listedIds)($this->doctor, ['period' => 'tomorrow']))->toBe([$tomorrow->id]);
});

it('lists the whole week from Saturday to Friday', function (): void {
    ($this->bookAt)('2026-09-11 23:30');
    $saturday = ($this->bookAt)('2026-09-12 09:00');
    $friday = ($this->bookAt)('2026-09-18 23:30');
    ($this->bookAt)('2026-09-19 00:00');

    expect(($this->listedIds)($this->doctor, ['period' => 'week']))->toBe([$saturday->id, $friday->id]);
});

it('lists the whole calendar month', function (): void {
    ($this->bookAt)('2026-08-31 23:30');
    $first = ($this->bookAt)('2026-09-01 00:00');
    $last = ($this->bookAt)('2026-09-30 23:30');
    ($this->bookAt)('2026-10-01 00:00');

    expect(($this->listedIds)($this->doctor, ['period' => 'month']))->toBe([$first->id, $last->id]);
});

it('keeps the newest first when no period is given', function (): void {
    $earlier = ($this->bookAt)('2026-09-17 09:00');
    $later = ($this->bookAt)('2026-09-17 11:00');

    expect(($this->listedIds)($this->doctor, []))->toBe([$later->id, $earlier->id]);
});

it('filters by an inclusive date range', function (): void {
    ($this->bookAt)('2026-09-19 23:30');
    $first = ($this->bookAt)('2026-09-20 00:00');
    $last = ($this->bookAt)('2026-09-22 23:30');
    ($this->bookAt)('2026-09-23 00:00');

    expect(($this->listedIds)($this->doctor, ['from' => '2026-09-20', 'to' => '2026-09-22']))
        ->toEqualCanonicalizing([$first->id, $last->id]);
});

it('only lists appointments the viewer may see within a period', function (): void {
    $own = ($this->bookAt)('2026-09-16 10:00');
    ($this->bookAt)('2026-09-16 10:00', User::factory()->doctor()->create());

    $secretary = User::factory()->secretary($this->doctor)->create();

    expect(($this->listedIds)($this->doctor, ['period' => 'today']))->toBe([$own->id])
        ->and(($this->listedIds)($secretary, ['period' => 'today']))->toBe([$own->id]);
});

it('lets an admin narrow the list to one doctor', function (): void {
    $own = ($this->bookAt)('2026-09-16 10:00');
    ($this->bookAt)('2026-09-16 11:00', User::factory()->doctor()->create());

    expect(($this->listedIds)(User::factory()->admin()->create(), ['doctor_id' => $this->doctor->id]))
        ->toBe([$own->id]);
});

it('rejects invalid filters', function (array $query, string $field): void {
    $this->actingAsPortal($this->doctor)
        ->getJson(route('api.v1.appointments.index', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown period' => [['period' => 'year'], 'period'],
    'unknown status' => [['status' => 'lost'], 'status'],
    'period with a range' => [['period' => 'today', 'from' => '2026-09-16'], 'period'],
    'range ending before it starts' => [['from' => '2026-09-20', 'to' => '2026-09-19'], 'to'],
    'malformed date' => [['from' => '16-09-2026'], 'from'],
]);

it('refuses a doctor filter from anyone but an admin', function (): void {
    $this->actingAsPortal($this->doctor)
        ->getJson(route('api.v1.appointments.index', ['doctor_id' => User::factory()->doctor()->create()->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');
});
