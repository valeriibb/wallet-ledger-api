<?php

declare(strict_types=1);

namespace App\Application\Currency\Port;

use App\Domain\Currency\ExchangeRate;
use App\Domain\Shared\ValueObject\Currency;

interface ExchangeRateProvider
{
    public function quote(Currency $base, Currency $quote): ExchangeRate;
}
