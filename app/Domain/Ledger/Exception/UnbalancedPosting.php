<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exception;

use DomainException;

final class UnbalancedPosting extends DomainException
{
    public static function forCurrency(string $currency): self
    {
        return new self("Posting debits and credits do not balance for {$currency}.");
    }
}
