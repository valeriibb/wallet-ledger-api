<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Port\AccountReader;

final readonly class ListAccounts
{
    public function __construct(private AccountReader $accounts) {}

    /** @return list<AccountSummary> */
    public function execute(string $ownerId): array
    {
        return $this->accounts->listUserWallets($ownerId);
    }
}
