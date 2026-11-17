<?php

declare(strict_types=1);

namespace App\Application\Outbox\Port;

use App\Application\Outbox\OutboxEvent;
use App\Application\Outbox\RelayBatchResult;
use Closure;

interface OutboxRelayStore
{
    /** @param Closure(OutboxEvent): void $publish */
    public function relayBatch(int $limit, Closure $publish): RelayBatchResult;
}
