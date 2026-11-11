<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;

final readonly class JournalEntry
{
    public function __construct(
        private string $accountId,
        private EntryDirection $direction,
        private Money $amount,
    ) {
        if ($accountId === '') {
            throw new InvalidArgumentException('Journal entry account ID cannot be empty.');
        }

        if ($amount->minorAmount() === 0) {
            throw new InvalidArgumentException('Journal entry amount must be greater than zero.');
        }
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function direction(): EntryDirection
    {
        return $this->direction;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->amount->currency();
    }
}
