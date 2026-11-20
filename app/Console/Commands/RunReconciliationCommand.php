<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Reconciliation\RunReconciliation;
use Illuminate\Console\Command;

final class RunReconciliationCommand extends Command
{
    protected $signature = 'reconciliation:run {--stale-outbox-minutes=15 : Pending outbox age threshold}';

    protected $description = 'Check ledger invariants and store a reconciliation report';

    public function handle(RunReconciliation $reconciliation): int
    {
        $threshold = filter_var($this->option('stale-outbox-minutes'), FILTER_VALIDATE_INT);

        if ($threshold === false) {
            $this->error('The stale outbox threshold must be an integer.');

            return self::INVALID;
        }

        $result = $reconciliation->execute($threshold);
        $this->info("Reconciliation {$result->runId}: {$result->status} ({$result->discrepancyCount} discrepancies).");

        return $result->discrepancyCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
