<?php

declare(strict_types=1);

namespace App\Application\Idempotency;

final readonly class StoredResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $statusCode,
        public array $body,
        public string $transactionId,
    ) {}
}
