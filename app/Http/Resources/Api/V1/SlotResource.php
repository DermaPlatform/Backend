<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One calendar slot produced by AvailabilityService.
 *
 * Wraps a plain array rather than a model, so the availability endpoint keeps
 * the same response shape as every other resource in the API.
 */
class SlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'start_at' => $this->resource['start_at']->toIso8601String(),
            'end_at' => $this->resource['end_at']->toIso8601String(),
            'available' => $this->resource['available'],
        ];
    }
}
