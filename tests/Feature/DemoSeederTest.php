<?php

use App\Models\AuditLog;
use App\Models\MedicalNote;
use App\Models\ScheduleException;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The demo data is what gets shown to a doctor and to the committee, so a
 * seeder that no longer runs is a broken demo.
 */
it('seeds a clinic with something to show on every screen, and can run twice', function (): void {
    Storage::fake(config('nadara.uploads.private_disk'));
    Storage::fake(config('nadara.uploads.disk'));

    $this->seed(DatabaseSeeder::class);

    $doctor = User::where('email', 'layla@nadara.test')->sole();

    $counts = fn (): array => [
        'users' => User::count(),
        'appointments' => $doctor->doctorAppointments()->count(),
        'notes' => MedicalNote::where('doctor_id', $doctor->id)->count(),
        'time off' => ScheduleException::where('doctor_id', $doctor->id)->count(),
        'audit entries' => AuditLog::count(),
    ];

    $first = $counts();

    expect($first['appointments'])->toBeGreaterThan(5)
        ->and($first['notes'])->toBeGreaterThan(0)
        ->and($first['time off'])->toBe(1)
        ->and($first['audit entries'])->toBeGreaterThan(0)
        ->and($doctor->isBookableDoctor())->toBeTrue();

    $this->seed(DemoSeeder::class);

    expect($counts())->toBe($first);

    // The screens the new data feeds all open for the demo doctor.
    $this->actingAsPortal($doctor);

    $this->getJson(route('api.v1.doctor.income', ['from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString()]))
        ->assertOk()
        ->assertJsonPath('data.appointments.count', 3);
    $this->getJson(route('api.v1.staff.schedule-exceptions.index'))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(route('api.v1.staff.patients.index'))->assertOk();
    $this->getJson(route('api.v1.appointments.index', ['period' => 'today']))->assertOk();
});
