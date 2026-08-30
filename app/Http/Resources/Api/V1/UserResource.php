<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Set when the resource is built for a caller who has just authenticated
     * and is therefore the owner of the record, even though the request itself
     * carries no token yet.
     */
    private bool $forOwner = false;

    /**
     * Mark this resource as the authenticated owner's own record.
     */
    public function asOwner(): static
    {
        $this->forOwner = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $showsClinicalDetails = $this->viewerMaySeeClinicalDetails($request);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'birth_date' => $this->when($showsClinicalDetails, fn (): ?string => $this->birth_date?->toDateString()),
            'age' => $this->when($showsClinicalDetails, fn (): ?int => $this->age),
            'gender' => $this->when($showsClinicalDetails, fn (): ?string => $this->gender?->value),
            'role' => $this->role?->value,
            'specialty' => $this->when($this->role === UserRole::Doctor, $this->specialty),
            'email_verified' => $this->email_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Birth date and gender drive treatment decisions, so they are limited to
     * the patient themselves and to the clinical staff who act on them.
     */
    private function viewerMaySeeClinicalDetails(Request $request): bool
    {
        if ($this->forOwner) {
            return true;
        }

        $viewer = $request->user();

        return $viewer !== null && (
            $viewer->id === $this->id
            || in_array($viewer->role, [UserRole::Doctor, UserRole::Admin], true)
        );
    }
}
