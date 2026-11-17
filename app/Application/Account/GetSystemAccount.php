<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Port\SystemAccountStore;
use App\Domain\Account\SystemAccountType;
use App\Domain\Shared\ValueObject\Currency;

final readonly class GetSystemAccount
{
    public function __construct(private SystemAccountStore $store) {}

    public function execute(SystemAccountType $type, Currency $currency): SystemAccount
    {
        return $this->store->getOrCreate($type, $currency);
    }
}
