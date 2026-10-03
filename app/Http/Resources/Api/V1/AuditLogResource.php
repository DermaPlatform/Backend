<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action?->value,
            'actor' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role?->value,
            ]),
            'subject' => [
                'type' => Str::snake(class_basename($this->auditable_type)),
                'id' => $this->auditable_id,
            ],
            'changes' => (object) $this->changes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
