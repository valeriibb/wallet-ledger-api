<?php

declare(strict_types=1);

namespace App\Domain\Conversion;

use App\Domain\Conversion\Exception\InvalidConversion;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Money;

final readonly class CreateConversionPosting
{
    public function execute(
        ConversionAccount $source,
        ConversionAccount $destination,
        ConversionAccount $sourceFx,
        ConversionAccount $destinationFx,
        Money $input,
        Money $output,
    ): Posting {
        if ($source->id === $destination->id) {
            throw new InvalidConversion('Source and destination accounts must be different.');
        }

        if ($source->status !== 'active' || $destination->status !== 'active') {
            throw new InvalidConversion('Both user accounts must be active.');
        }

        if ($source->type !== 'user_wallet' || $destination->type !== 'user_wallet') {
            throw new InvalidConversion('A conversion requires two user wallets.');
        }

        if ($sourceFx->type !== 'system_fx' || $destinationFx->type !== 'system_fx') {
            throw new InvalidConversion('A conversion requires FX system accounts.');
        }

        if (! $source->currency->equals($input->currency())
            || ! $sourceFx->currency->equals($input->currency())
            || ! $destination->currency->equals($output->currency())
            || ! $destinationFx->currency->equals($output->currency())) {
            throw new InvalidConversion('Account currencies must match the conversion currencies.');
        }

        if ($input->currency()->equals($output->currency())) {
            throw new InvalidConversion('Source and destination currencies must be different.');
        }

        if ($output->minorAmount() === 0) {
            throw new InvalidConversion('The converted amount is below one minor unit.');
        }

        return new Posting([
            new JournalEntry($source->id, EntryDirection::Debit, $input),
            new JournalEntry($sourceFx->id, EntryDirection::Credit, $input),
            new JournalEntry($destinationFx->id, EntryDirection::Debit, $output),
            new JournalEntry($destination->id, EntryDirection::Credit, $output),
        ]);
    }
}
