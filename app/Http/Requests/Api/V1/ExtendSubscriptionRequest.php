<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExtendSubscriptionRequest extends FormRequest
{
    /**
     * Route access is already restricted to admins by the role middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ends_at' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.CarbonImmutable::now(config('nadara.clinic_timezone'))->toDateString(),
            ],
            'status' => ['sometimes', Rule::in([SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])],
        ];
    }

    /**
     * The subscription covers the whole of its last day as the clinic's
     * clock reads it, so a doctor paid up to the 31st works through the 31st.
     */
    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('ends_at'), config('nadara.clinic_timezone'))
            ->endOfDay()
            ->utc();
    }

    /**
     * Extending means the doctor paid unless the admin says the trial itself
     * is being prolonged.
     */
    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::from($this->validated('status', SubscriptionStatus::Active->value));
    }
}
