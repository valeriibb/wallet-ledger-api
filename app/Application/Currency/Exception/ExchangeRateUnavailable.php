<?php

declare(strict_types=1);

namespace App\Application\Currency\Exception;

use RuntimeException;

final class ExchangeRateUnavailable extends RuntimeException
{
    public static function forPair(string $base, string $quote): self
    {
        return new self("The {$base}/{$quote} exchange rate is unavailable.");
    }
}
