<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * A doctor as the public catalog sees them. Deliberately narrower than
 * UserResource: this is reachable without a token, so it carries the
 * professional profile and nothing personal.
 */
class DoctorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'specialty' => $this->whenLoaded('doctorProfile', fn (): ?string => $this->doctorProfile->specialty),
            'accepts_appointments' => $this->whenLoaded(
                'doctorProfile',
                fn (): bool => $this->doctorProfile->accepts_appointments,
            ),
            'accepts_online_consultations' => $this->whenLoaded(
                'doctorProfile',
                fn (): bool => $this->doctorProfile->accepts_online_consultations,
            ),
            'services' => DoctorServiceResource::collection($this->whenLoaded('offeredServices')),
        ];
    }
}
