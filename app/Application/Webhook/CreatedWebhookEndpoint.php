<?php

declare(strict_types=1);

namespace App\Application\Webhook;

final readonly class CreatedWebhookEndpoint
{
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $signingSecret,
    ) {}
}
