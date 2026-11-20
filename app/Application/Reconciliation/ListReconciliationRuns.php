<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Application\Reconciliation\Port\ReconciliationStore;

final readonly class ListReconciliationRuns
{
    public function __construct(private ReconciliationStore $store) {}

    /** @return list<ReconciliationRun> */
    public function execute(): array
    {
        return $this->store->latest(50);
    }
}
