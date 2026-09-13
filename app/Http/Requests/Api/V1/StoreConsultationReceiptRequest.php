<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConsultationReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('payFor', $this->route('consultation'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'receipt' => [
                'required',
                'file',
                'mimes:'.implode(',', config('nadara.uploads.receipt_mimes')),
                'max:'.config('nadara.uploads.receipt_max_kb'),
            ],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
