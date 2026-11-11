<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class AccountSummary
{
    public function __construct(
        public string $id,
        public string $type,
        public string $currency,
        public string $status,
    ) {}
}
