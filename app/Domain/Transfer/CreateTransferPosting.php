<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Exception\InvalidTransfer;

final class CreateTransferPosting
{
    public function execute(TransferAccount $source, TransferAccount $destination, Money $money): Posting
    {
        if ($source->id === $destination->id) {
            throw InvalidTransfer::sameAccount();
        }

        if ($source->status !== 'active' || $destination->status !== 'active') {
            throw InvalidTransfer::inactiveAccount();
        }

        if ($source->type !== 'user_wallet' || $destination->type !== 'user_wallet') {
            throw InvalidTransfer::unsupportedAccountType();
        }

        if (! $source->currency->equals($money->currency())
            || ! $destination->currency->equals($money->currency())) {
            throw InvalidTransfer::currencyMismatch();
        }

        return new Posting([
            new JournalEntry($source->id, EntryDirection::Debit, $money),
            new JournalEntry($destination->id, EntryDirection::Credit, $money),
        ]);
    }
}
