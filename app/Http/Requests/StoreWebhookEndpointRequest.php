<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Webhook\WebhookEventType;
use Illuminate\Validation\Rule;

final class StoreWebhookEndpointRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::enum(WebhookEventType::class)],
        ];
    }
}
