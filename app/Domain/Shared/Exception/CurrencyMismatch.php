<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use DomainException;

final class CurrencyMismatch extends DomainException
{
    public static function between(string $left, string $right): self
    {
        return new self("Cannot calculate money in {$left} and {$right}.");
    }
}
