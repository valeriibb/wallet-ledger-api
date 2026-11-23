<?php

declare(strict_types=1);

namespace App\Application\Conversion;

use App\Application\Conversion\Port\ConversionStore;
use App\Domain\Currency\ExchangeRate;
use App\Domain\Shared\ValueObject\Money;

final readonly class ConvertCurrency
{
    public function __construct(private ConversionStore $store) {}

    public function execute(
        string $actorId,
        string $sourceAccountId,
        string $destinationAccountId,
        Money $input,
        ExchangeRate $rate,
        ?string $reference,
    ): ConversionResult {
        $output = $rate->convertDetailed($input);
        $transactionId = $this->store->convert(
            $actorId,
            $sourceAccountId,
            $destinationAccountId,
            $input,
            $output,
            $rate,
            $reference,
        );

        return new ConversionResult(
            $transactionId,
            $sourceAccountId,
            $destinationAccountId,
            $input->minorAmount(),
            $input->currency()->code(),
            $output->money->minorAmount(),
            $output->money->currency()->code(),
            $rate->decimal(),
        );
    }
}
