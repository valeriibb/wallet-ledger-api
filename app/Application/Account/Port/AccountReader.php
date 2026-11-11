<?php

declare(strict_types=1);

namespace App\Application\Account\Port;

use App\Application\Account\AccountBalance;
use App\Application\Account\AccountSummary;
use App\Application\Account\StatementPage;

interface AccountReader
{
    /** @return list<AccountSummary> */
    public function listUserWallets(string $ownerId): array;

    public function balance(string $accountId): AccountBalance;

    public function statement(string $accountId, int $limit, ?string $cursor): StatementPage;
}
