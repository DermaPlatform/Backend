<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ScheduleException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduleException
 */
class ScheduleExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'doctor_id' => $this->doctor_id,
            'date' => $this->date?->toDateString(),
            'starts_at' => $this->starts_at === null ? null : substr($this->starts_at, 0, 5),
            'ends_at' => $this->ends_at === null ? null : substr($this->ends_at, 0, 5),
            'full_day' => $this->starts_at === null && $this->ends_at === null,
            'reason' => $this->reason,
        ];
    }
}
