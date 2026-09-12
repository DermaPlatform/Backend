<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Consultation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Consultation
 */
class ConsultationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'symptoms' => $this->symptoms,
            'price' => $this->price,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'replied_at' => $this->replied_at?->toIso8601String(),
            'patient' => new UserResource($this->whenLoaded('patient')),
            'doctor' => new UserResource($this->whenLoaded('doctor')),
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'messages' => ConsultationMessageResource::collection($this->whenLoaded('messages')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
