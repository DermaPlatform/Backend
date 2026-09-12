<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            /*
             * Sent in the clinic's own timezone. The offset travels with the
             * string, so a client rendering it verbatim shows clinic hours and
             * one parsing it still gets the right instant.
             */
            'start_at' => $this->start_at?->timezone(config('nadara.clinic_timezone'))->toIso8601String(),
            'end_at' => $this->end_at?->timezone(config('nadara.clinic_timezone'))->toIso8601String(),
            'price' => $this->price,
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'rejection_reason' => $this->rejection_reason,
            'refund_required' => $this->refund_required,
            'patient' => new UserResource($this->whenLoaded('patient')),
            'doctor' => new UserResource($this->whenLoaded('doctor')),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
