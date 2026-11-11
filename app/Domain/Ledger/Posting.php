<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exception\UnbalancedPosting;
use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;

final readonly class Posting
{
    /**
     * @param  non-empty-list<JournalEntry>  $entries
     */
    public function __construct(private array $entries)
    {
        if (count($entries) < 2) {
            throw new InvalidArgumentException('A posting must contain at least two entries.');
        }

        $this->assertBalancedByCurrency();
    }

    /**
     * @return non-empty-list<JournalEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    private function assertBalancedByCurrency(): void
    {
        /** @var array<string, array<string, Money>> $totals */
        $totals = [];

        foreach ($this->entries as $entry) {
            $currency = $entry->currency()->code();
            $direction = $entry->direction()->value;
            $amount = $entry->amount();

            $totals[$currency][$direction] = isset($totals[$currency][$direction])
                ? $totals[$currency][$direction]->add($amount)
                : $amount;
        }

        foreach ($totals as $currency => $directions) {
            $debits = $directions[EntryDirection::Debit->value] ?? null;
            $credits = $directions[EntryDirection::Credit->value] ?? null;

            if ($debits === null || $credits === null || ! $debits->equals($credits)) {
                throw UnbalancedPosting::forCurrency($currency);
            }
        }
    }
}
