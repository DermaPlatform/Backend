<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Consultation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Consultation::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'doctor'),
            ],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'symptoms' => ['required', 'string', 'min:20', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:'.config('nadara.uploads.consultation_image_max_files')],
            'photos.*' => [
                'image',
                'mimes:'.implode(',', config('nadara.uploads.image_mimes')),
                'max:'.config('nadara.uploads.image_max_kb'),
            ],
        ];
    }
}
