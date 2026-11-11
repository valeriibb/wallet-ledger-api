<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exception;

use DomainException;

final class InsufficientFunds extends DomainException
{
    public static function forAccount(string $accountId): self
    {
        return new self("Account {$accountId} does not have enough funds.");
    }
}
