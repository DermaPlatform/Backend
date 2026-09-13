<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Consultation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => Consultation::class,
            'attachable_id' => Consultation::factory(),
            'path' => 'consultations/'.fake()->uuid().'.jpg',
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(20_000, 900_000),
        ];
    }
}
