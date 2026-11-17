<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Application\Outbox\OutboxEvent;
use App\Application\Outbox\Port\EventPublisher;
use Illuminate\Support\Facades\Log;

final class LogEventPublisher implements EventPublisher
{
    public function publish(OutboxEvent $event): void
    {
        Log::info('Outbox event published.', [
            'event_id' => $event->id,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'event_type' => $event->eventType,
            'payload' => $event->payload,
            'occurred_at' => $event->occurredAt,
        ]);
    }
}
