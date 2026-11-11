<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class OpenedAccount
{
    public function __construct(
        public string $id,
        public string $currency,
        public string $status,
        public int $balance,
    ) {}
}
