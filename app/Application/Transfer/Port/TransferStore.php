<?php

declare(strict_types=1);

namespace App\Application\Transfer\Port;

use App\Domain\Shared\ValueObject\Money;

interface TransferStore
{
    public function transfer(
        string $actorId,
        string $sourceAccountId,
        string $destinationAccountId,
        Money $money,
        ?string $reference,
    ): string;
}
