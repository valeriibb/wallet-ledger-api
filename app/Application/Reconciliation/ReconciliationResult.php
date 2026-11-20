<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

final readonly class ReconciliationResult
{
    /** @param array<string, int> $countsByKind */
    public function __construct(
        public string $runId,
        public string $status,
        public int $discrepancyCount,
        public array $countsByKind,
    ) {}
}
