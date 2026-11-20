<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Reconciliation\ListReconciliationRuns;
use App\Application\Reconciliation\ReconciliationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final readonly class ReconciliationController
{
    public function __construct(private ListReconciliationRuns $runs) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewReconciliation');

        return response()->json([
            'data' => array_map(static fn (ReconciliationRun $run): array => [
                'id' => $run->id,
                'status' => $run->status,
                'summary' => $run->summary,
                'started_at' => $run->startedAt,
                'finished_at' => $run->finishedAt,
                'discrepancies' => $run->discrepancies,
            ], $this->runs->execute()),
        ]);
    }
}
