<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * A patient as the front desk needs them: who they are and how to reach
 * them. Nothing clinical, whoever is asking, so this list is safe to put in
 * front of a secretary.
 */
class StaffPatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'image_url' => $this->image_url,
            'appointments_count' => $this->whenCounted('appointments'),
            'last_appointment_at' => $this->when(
                array_key_exists('last_appointment_at', $attributes),
                fn (): ?string => $attributes['last_appointment_at'] === null
                    ? null
                    : CarbonImmutable::parse($attributes['last_appointment_at'], 'UTC')
                        ->timezone(config('nadara.clinic_timezone'))
                        ->toIso8601String(),
            ),
        ];
    }
}
