<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 *
 * A service as one doctor prices it. The price and duration come from the
 * pivot, never from the service row, because the catalog figure is only a
 * hint once a doctor has set their own.
 */
class DoctorServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'price' => $this->pivot->price,
            'duration_minutes' => $this->pivot->duration_minutes,
        ];
    }
}
