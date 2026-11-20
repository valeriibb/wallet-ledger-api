<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Port;

use App\Application\Reconciliation\ReconciliationResult;
use App\Application\Reconciliation\ReconciliationRun;

interface ReconciliationStore
{
    public function run(int $staleOutboxMinutes): ReconciliationResult;

    /** @return list<ReconciliationRun> */
    public function latest(int $limit): array;
}
