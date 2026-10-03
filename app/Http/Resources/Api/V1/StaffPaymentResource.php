<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

/**
 * A payment as the review queue lists it: the payment itself, plus who paid
 * and what for, which a single payment shown inside its appointment never
 * needed to repeat.
 */
class StaffPaymentResource extends PaymentResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'appointment_id' => $this->appointment_id,
            'consultation_id' => $this->consultation_id,
            'patient' => new StaffPatientResource($this->whenLoaded('patient')),
            'appointment' => $this->when(
                $this->appointment_id !== null && $this->relationLoaded('appointment'),
                fn (): array => [
                    'id' => $this->appointment->id,
                    'status' => $this->appointment->status?->value,
                    'start_at' => $this->appointment->start_at
                        ?->timezone(config('nadara.clinic_timezone'))
                        ->toIso8601String(),
                    'service' => $this->appointment->service?->name,
                    'doctor' => $this->appointment->doctor?->name,
                ],
            ),
        ];
    }
}
