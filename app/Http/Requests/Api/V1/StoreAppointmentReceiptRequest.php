<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('payFor', $this->route('appointment'));
    }

    /**
     * A receipt may be a photograph of a transfer slip or a bank PDF, so this
     * is deliberately wider than the image rules used elsewhere.
     *
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
