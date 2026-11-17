<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\SystemAccountType;

final readonly class SystemAccount
{
    public function __construct(
        public string $id,
        public SystemAccountType $type,
        public string $currency,
    ) {}
}
