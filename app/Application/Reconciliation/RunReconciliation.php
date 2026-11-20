<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Port\ReconciliationStore;
use InvalidArgumentException;

final readonly class RunReconciliation
{
    public function __construct(private ReconciliationStore $store) {}

    public function execute(int $staleOutboxMinutes = 15): ReconciliationResult
    {
        if ($staleOutboxMinutes < 1 || $staleOutboxMinutes > 1440) {
            throw new InvalidArgumentException('The stale outbox threshold must be between 1 and 1440 minutes.');
        }

        return $this->store->run($staleOutboxMinutes);
    }
}
