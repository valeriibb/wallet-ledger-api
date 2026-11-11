<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Port\AccountStore;
use App\Domain\Shared\ValueObject\Currency;

final readonly class OpenAccount
{
    public function __construct(private AccountStore $accounts) {}

    public function execute(string $ownerId, Currency $currency): OpenedAccount
    {
        return $this->accounts->createUserWallet($ownerId, $currency);
    }
}
