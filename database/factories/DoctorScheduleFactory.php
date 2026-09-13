<?php

namespace Database\Factories;

use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorSchedule>
 */
class DoctorScheduleFactory extends Factory
{
    protected $model = DoctorSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->doctor(),
            'weekday' => fake()->numberBetween(0, 6),
            'starts_at' => '09:00:00',
            'ends_at' => '17:00:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ];
    }
}
