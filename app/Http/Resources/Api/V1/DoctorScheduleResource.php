<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DoctorSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoctorSchedule
 */
class DoctorScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'doctor_id' => $this->doctor_id,
            'weekday' => $this->weekday,
            'starts_at' => substr($this->starts_at, 0, 5),
            'ends_at' => substr($this->ends_at, 0, 5),
            'slot_minutes' => $this->slot_minutes,
            'is_active' => $this->is_active,
        ];
    }
}
