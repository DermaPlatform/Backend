<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            /*
             * Pricing lives on doctor_service now, so the catalog shows the
             * cheapest doctor's figure and falls back to the service hint only
             * while nobody has priced it yet.
             */
            'starting_price' => $this->when(
                $this->starting_price !== null,
                fn (): string => number_format((float) $this->starting_price, 2, '.', '')
            ),
            'bookable_doctors_count' => $this->whenNotNull($this->bookable_doctors_count),
            'doctors' => DoctorResource::collection($this->whenLoaded('bookableDoctors')),
            'description' => $this->description,
            'image_url' => $this->image_url,
            'is_active' => $this->is_active,
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
