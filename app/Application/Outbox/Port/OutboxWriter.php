<?php

declare(strict_types=1);

namespace App\Application\Outbox\Port;

interface OutboxWriter
{
    /** @param array<string, mixed> $payload */
    public function append(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
    ): string;
}
