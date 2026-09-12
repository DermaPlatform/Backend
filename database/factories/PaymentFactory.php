<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'consultation_id' => null,
            'patient_id' => fn (array $attributes): int => Appointment::query()
                ->findOrFail($attributes['appointment_id'])
                ->patient_id,
            'amount' => fn (array $attributes): string => Appointment::query()
                ->findOrFail($attributes['appointment_id'])
                ->price,
            'receipt_number' => null,
            'receipt_path' => null,
            'method' => null,
            'reference' => null,
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => null,
            'paid_at' => null,
            'status' => PaymentStatus::Pending,
        ];
    }
}
