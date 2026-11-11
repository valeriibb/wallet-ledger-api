<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Port\AccountReader;

final readonly class GetAccountBalance
{
    public function __construct(private AccountReader $accounts) {}

    public function execute(string $accountId): AccountBalance
    {
        return $this->accounts->balance($accountId);
    }
}
