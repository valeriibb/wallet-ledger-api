<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

final readonly class ReversePosting
{
    /** @param list<JournalEntry> $originalEntries */
    public function execute(array $originalEntries): Posting
    {
        if ($originalEntries === []) {
            throw new InvalidArgumentException('A posting cannot be reversed without entries.');
        }

        return new Posting(array_map(
            static fn (JournalEntry $entry): JournalEntry => new JournalEntry(
                $entry->accountId(),
                $entry->direction() === EntryDirection::Debit
                    ? EntryDirection::Credit
                    : EntryDirection::Debit,
                $entry->amount(),
            ),
            $originalEntries,
        ));
    }
}
