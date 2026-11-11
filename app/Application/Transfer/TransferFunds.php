<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Transfer\Port\TransferStore;
use App\Domain\Shared\ValueObject\Money;

final readonly class TransferFunds
{
    public function __construct(private TransferStore $store) {}

    public function execute(
        string $actorId,
        string $sourceAccountId,
        string $destinationAccountId,
        Money $money,
        ?string $reference,
    ): TransferResult {
        $transactionId = $this->store->transfer(
            $actorId,
            $sourceAccountId,
            $destinationAccountId,
            $money,
            $reference,
        );

        return new TransferResult(
            $transactionId,
            $sourceAccountId,
            $destinationAccountId,
            $money->minorAmount(),
            $money->currency()->code(),
        );
    }
}
