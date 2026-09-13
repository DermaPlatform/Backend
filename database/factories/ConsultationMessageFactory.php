<?php

namespace Database\Factories;

use App\Models\Consultation;
use App\Models\ConsultationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultationMessage>
 */
class ConsultationMessageFactory extends Factory
{
    protected $model = ConsultationMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consultation_id' => Consultation::factory(),
            'sender_id' => fn (array $attributes): int => Consultation::query()
                ->findOrFail($attributes['consultation_id'])
                ->patient_id,
            'message' => fake()->paragraph(),
        ];
    }
}
