<?php

namespace Database\Factories;

use App\Models\ScheduleException;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleException>
 */
class ScheduleExceptionFactory extends Factory
{
    protected $model = ScheduleException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->numberBetween(8, 15);

        return [
            'doctor_id' => User::factory()->doctor(),
            'date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'starts_at' => sprintf('%02d:00:00', $startsAt),
            'ends_at' => sprintf('%02d:00:00', $startsAt + 1),
            'reason' => fake()->sentence(),
        ];
    }

    /**
     * A whole day off. Both times are null, which is how the table says the
     * exception covers the entire day rather than a window inside it.
     */
    public function fullDay(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }
}
