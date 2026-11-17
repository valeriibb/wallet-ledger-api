<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum SystemAccountType: string
{
    case Cash = 'system_cash';
    case Fees = 'system_fees';
    case Fx = 'system_fx';
    case Suspense = 'system_suspense';
}
