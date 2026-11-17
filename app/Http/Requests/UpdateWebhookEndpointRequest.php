<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Webhook\WebhookEventType;
use Illuminate\Validation\Rule;

final class UpdateWebhookEndpointRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'required', 'string', 'max:2048'],
            'events' => ['sometimes', 'required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::enum(WebhookEventType::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
