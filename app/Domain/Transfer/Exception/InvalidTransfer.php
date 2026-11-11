<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use DomainException;

final class InvalidTransfer extends DomainException
{
    public static function sameAccount(): self
    {
        return new self('Source and destination accounts must be different.');
    }

    public static function inactiveAccount(): self
    {
        return new self('Both accounts must be active.');
    }

    public static function unsupportedAccountType(): self
    {
        return new self('Transfers are supported only between user wallets.');
    }

    public static function currencyMismatch(): self
    {
        return new self('The transfer currency must match both accounts.');
    }
}
