<?php

namespace Database\Factories;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    protected $model = Consultation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => User::factory()->patient(),
            'doctor_id' => User::factory()->doctor(),
            'symptoms' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 50, 500),
            'submitted_at' => now(),
            'replied_at' => null,
            'status' => ConsultationStatus::PendingPayment,
        ];
    }
}
