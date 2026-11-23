<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Domain\Shared\ValueObject\Money;

final readonly class ConvertedMoney
{
    public function __construct(
        public Money $money,
        public string $exactMinorAmount,
        public string $roundingDifference,
    ) {}
}
