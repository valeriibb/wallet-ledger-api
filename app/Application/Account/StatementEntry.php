<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class StatementEntry
{
    public function __construct(
        public string $id,
        public string $transactionId,
        public string $direction,
        public int $amount,
        public string $currency,
        public int $balanceAfter,
        public string $createdAt,
    ) {}
}
