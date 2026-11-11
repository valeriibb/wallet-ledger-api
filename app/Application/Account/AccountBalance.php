<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class AccountBalance
{
    public function __construct(
        public string $accountId,
        public string $currency,
        public int $balance,
    ) {}
}
