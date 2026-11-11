<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Port\AccountReader;

final readonly class GetAccountStatement
{
    public function __construct(private AccountReader $accounts) {}

    public function execute(string $accountId, int $limit, ?string $cursor): StatementPage
    {
        return $this->accounts->statement($accountId, $limit, $cursor);
    }
}
