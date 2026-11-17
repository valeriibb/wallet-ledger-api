<?php

declare(strict_types=1);

namespace App\Application\Cash\Port;

use App\Domain\Shared\ValueObject\Money;

interface CashOperationStore
{
    public function deposit(
        string $actorId,
        string $walletId,
        string $systemCashAccountId,
        Money $money,
        string $providerReference,
    ): string;

    public function withdraw(
        string $actorId,
        string $walletId,
        string $systemCashAccountId,
        Money $money,
        string $providerReference,
    ): string;
}
