<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $durationMinutes = 30;
        $startAt = now()
            ->addDays(fake()->numberBetween(1, 30))
            ->setTime(fake()->numberBetween(8, 16), 0);

        return [
            'patient_id' => User::factory()->patient(),
            'doctor_id' => User::factory()->doctor(),
            'service_id' => Service::factory(),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes($durationMinutes),
            'hold_expires_at' => now()->addMinutes(15),
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'price' => fake()->randomFloat(2, 50, 500),
            'status' => AppointmentStatus::PendingPayment,
        ];
    }

    /**
     * An appointment waiting for payment during its valid hold period.
     */
    public function pendingPayment(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentStatus::PendingPayment,
            'hold_expires_at' => now()->addMinutes(15),
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /**
     * An appointment whose payment receipt is awaiting staff review.
     */
    public function awaitingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentStatus::AwaitingReview,
            'hold_expires_at' => null,
        ]);
    }

    /**
     * An appointment approved by an administrator.
     */
    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentStatus::Approved,
            'hold_expires_at' => null,
            'approved_by' => User::factory()->admin(),
            'approved_at' => now(),
        ]);
    }

    /**
     * An appointment still awaiting payment whose hold window has already
     * passed. This is the input the ReleaseExpiredHolds command acts on, not
     * the result it produces.
     */
    public function holdExpired(): static
    {
        return $this->state(fn (): array => [
            'status' => AppointmentStatus::PendingPayment,
            'hold_expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Assign the appointment to a specific doctor.
     */
    public function forDoctor(User $doctor): static
    {
        return $this->state(fn (): array => [
            'doctor_id' => $doctor->id,
        ]);
    }
}
