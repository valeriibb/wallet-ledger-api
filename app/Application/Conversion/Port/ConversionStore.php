<?php

declare(strict_types=1);

namespace App\Application\Conversion\Port;

use App\Domain\Currency\ConvertedMoney;
use App\Domain\Currency\ExchangeRate;
use App\Domain\Shared\ValueObject\Money;

interface ConversionStore
{
    public function convert(
        string $actorId,
        string $sourceAccountId,
        string $destinationAccountId,
        Money $input,
        ConvertedMoney $output,
        ExchangeRate $rate,
        ?string $reference,
    ): string;
}
