<?php

declare(strict_types=1);

namespace App\Application\Conversion;

final readonly class ConversionResult
{
    public function __construct(
        public string $transactionId,
        public string $sourceAccountId,
        public string $destinationAccountId,
        public int $inputAmount,
        public string $inputCurrency,
        public int $outputAmount,
        public string $outputCurrency,
        public string $rate,
    ) {}
}
