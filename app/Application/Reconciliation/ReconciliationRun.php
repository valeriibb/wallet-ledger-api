<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

final readonly class ReconciliationRun
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array{id: string, kind: string, account_id: ?string, expected: int, actual: int, details: array<string, mixed>}>  $discrepancies
     */
    public function __construct(
        public string $id,
        public string $status,
        public array $summary,
        public string $startedAt,
        public ?string $finishedAt,
        public array $discrepancies,
    ) {}
}
