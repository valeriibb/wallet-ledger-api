<?php

declare(strict_types=1);

namespace App\Application\Reversal;

use App\Application\Idempotency\IdempotencyResult;
use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Idempotency\StoredResponse;
use App\Application\Reversal\Port\ReversalStore;

final readonly class ReverseTransaction
{
    public function __construct(
        private ReversalStore $store,
        private IdempotencyExecutor $idempotency,
    ) {}

    public function execute(string $actorId, string $originalTransactionId, string $key): IdempotencyResult
    {
        $hash = hash('sha256', "reversal|{$originalTransactionId}");

        return $this->idempotency->execute(
            $actorId,
            $key,
            $hash,
            function () use ($actorId, $originalTransactionId): StoredResponse {
                $transactionId = $this->store->reverse($actorId, $originalTransactionId);

                return new StoredResponse(201, ['data' => [
                    'transaction_id' => $transactionId,
                    'reversal_of' => $originalTransactionId,
                    'status' => 'posted',
                ]], $transactionId);
            },
        );
    }
}
