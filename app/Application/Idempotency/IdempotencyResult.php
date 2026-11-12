<?php

declare(strict_types=1);

namespace App\Application\Idempotency;

final readonly class IdempotencyResult
{
    public function __construct(
        public StoredResponse $response,
        public bool $replayed,
    ) {}
}
