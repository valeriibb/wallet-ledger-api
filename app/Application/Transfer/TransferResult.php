<?php

declare(strict_types=1);

namespace App\Application\Transfer;

final readonly class TransferResult
{
    public function __construct(
        public string $transactionId,
        public string $sourceAccountId,
        public string $destinationAccountId,
        public int $amount,
        public string $currency,
    ) {}
}
