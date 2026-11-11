<?php

declare(strict_types=1);

namespace App\Application\Account\Exception;

use RuntimeException;

final class AccountAlreadyExists extends RuntimeException
{
    public static function forCurrency(string $currency): self
    {
        return new self("A wallet for {$currency} already exists.");
    }
}
