<?php

declare(strict_types=1);

namespace App\Application\Fee\Port;

use App\Domain\Shared\ValueObject\Money;

interface FeeStore
{
    public function charge(
        string $actorId,
        string $walletId,
        string $systemFeesAccountId,
        Money $money,
        ?string $reference,
    ): string;
}
