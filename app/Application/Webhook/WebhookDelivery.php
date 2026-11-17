<?php

declare(strict_types=1);

namespace App\Application\Webhook;

final readonly class WebhookDelivery
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $eventId,
        public string $eventType,
        public string $url,
        public string $secret,
        public array $payload,
        public int $attempt,
    ) {}
}
