<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Minishlink\WebPush\ContentEncoding;
use NotificationChannels\WebPush\PushSubscription;

class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * The route is behind auth:sanctum, and a subscription is always stored
     * for the caller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The shape is what PushSubscription.toJSON() produces in the browser.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'endpoint' => [
                'required',
                'string',
                'url:https',
                'max:'.PushSubscription::ENDPOINT_MAX_LENGTH,
                $this->isKnownPushService(...),
            ],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['sometimes', Rule::enum(ContentEncoding::class)],
        ];
    }

    /**
     * The server later posts to this address, so it must belong to a browser
     * vendor's push service. Accepting any URL would let a caller aim the
     * server's own requests at whatever host they like.
     */
    private function isKnownPushService(string $attribute, mixed $value, Closure $fail): void
    {
        $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

        $known = collect(config('nadara.push.allowed_hosts'))->contains(
            fn (string $allowed): bool => $host === $allowed || str_ends_with($host, '.'.$allowed)
        );

        if (! $known) {
            $fail('The :attribute is not a recognised push service.');
        }
    }
}
