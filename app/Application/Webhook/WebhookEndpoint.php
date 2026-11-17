<?php

declare(strict_types=1);

namespace App\Application\Webhook;

final readonly class WebhookEndpoint
{
    /** @param list<string> $events */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
        public bool $isActive,
        public string $createdAt,
    ) {}
}
