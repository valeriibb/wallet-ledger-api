<?php

declare(strict_types=1);

namespace App\Application\Idempotency\Port;

use App\Application\Idempotency\IdempotencyResult;
use App\Application\Idempotency\StoredResponse;
use Closure;

interface IdempotencyExecutor
{
    /** @param Closure(): StoredResponse $operation */
    public function execute(
        string $ownerId,
        string $key,
        string $requestHash,
        Closure $operation,
    ): IdempotencyResult;
}
