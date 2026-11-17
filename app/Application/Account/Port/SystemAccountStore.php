<?php

declare(strict_types=1);

namespace App\Application\Account\Port;

use App\Application\Account\SystemAccount;
use App\Domain\Account\SystemAccountType;
use App\Domain\Shared\ValueObject\Currency;

interface SystemAccountStore
{
    public function getOrCreate(SystemAccountType $type, Currency $currency): SystemAccount;
}
