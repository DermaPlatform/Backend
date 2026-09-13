<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\MedicalNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalNote>
 */
class MedicalNoteFactory extends Factory
{
    protected $model = MedicalNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'patient_id' => fn (array $attributes): int => Appointment::query()
                ->findOrFail($attributes['appointment_id'])
                ->patient_id,
            'doctor_id' => fn (array $attributes): int => Appointment::query()
                ->findOrFail($attributes['appointment_id'])
                ->doctor_id,
            'notes' => fake()->paragraph(),
        ];
    }
}
