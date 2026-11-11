<?php

declare(strict_types=1);

namespace App\Application\Account\Port;

use App\Application\Account\OpenedAccount;
use App\Domain\Shared\ValueObject\Currency;

interface AccountStore
{
    public function createUserWallet(string $ownerId, Currency $currency): OpenedAccount;
}
