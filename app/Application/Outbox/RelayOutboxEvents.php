<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use App\Application\Outbox\Port\EventPublisher;
use App\Application\Outbox\Port\OutboxRelayStore;
use InvalidArgumentException;

final readonly class RelayOutboxEvents
{
    public function __construct(
        private OutboxRelayStore $store,
        private EventPublisher $publisher,
    ) {}

    public function execute(int $batchSize = 100): RelayBatchResult
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('Outbox batch size must be between 1 and 1000.');
        }

        return $this->store->relayBatch($batchSize, function (OutboxEvent $event): void {
            $this->publisher->publish($event);
        });
    }
}
