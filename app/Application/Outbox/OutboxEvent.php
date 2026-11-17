<?php

declare(strict_types=1);

namespace App\Application\Outbox;

final readonly class OutboxEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $aggregateType,
        public string $aggregateId,
        public string $eventType,
        public array $payload,
        public string $occurredAt,
        public int $attempts,
    ) {}
}
