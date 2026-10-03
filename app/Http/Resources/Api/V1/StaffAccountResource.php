<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * A doctor or secretary account as the platform admin manages it (US-025).
 * Kept apart from UserResource, which is shaped around patients, and from
 * DoctorResource, which is public and so carries no contact details.
 */
class StaffAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isDoctor = $this->role === UserRole::Doctor;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'image_url' => $this->image_url,
            'role' => $this->role?->value,
            'is_active' => $this->is_active,
            'doctor_id' => $this->when($this->role === UserRole::Secretary, fn (): ?int => $this->doctor_id),
            'specialty' => $this->when($isDoctor, fn (): ?string => $this->doctorProfile?->specialty),
            'subscription' => $this->when($isDoctor, fn (): array => [
                'status' => $this->doctorProfile?->subscriptionStanding()->value,
                'ends_at' => $this->doctorProfile?->subscription_ends_at?->toIso8601String(),
                'grace_ends_at' => $this->doctorProfile?->graceEndsAt()?->toIso8601String(),
            ]),
            'secretaries' => self::collection($this->whenLoaded('secretaries')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
