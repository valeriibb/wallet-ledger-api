<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ledger;

use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\Exception\UnbalancedPosting;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostingTest extends TestCase
{
    private Currency $usd;

    protected function setUp(): void
    {
        $this->usd = Currency::fromCode('USD');
    }

    #[Test]
    public function it_accepts_balanced_entries_in_one_currency(): void
    {
        $posting = new Posting([
            $this->entry('account-a', EntryDirection::Debit, 1_000),
            $this->entry('account-b', EntryDirection::Credit, 400),
            $this->entry('account-c', EntryDirection::Credit, 600),
        ]);

        self::assertCount(3, $posting->entries());
    }

    #[Test]
    public function it_accepts_balanced_entries_in_multiple_currencies(): void
    {
        $posting = new Posting([
            $this->entry('account-a', EntryDirection::Debit, 1_000),
            $this->entry('account-b', EntryDirection::Credit, 1_000),
            $this->entry('account-c', EntryDirection::Debit, 250, 'EUR'),
            $this->entry('account-d', EntryDirection::Credit, 250, 'EUR'),
        ]);

        self::assertCount(4, $posting->entries());
    }

    #[Test]
    public function it_rejects_unbalanced_entries_in_a_currency(): void
    {
        $this->expectException(UnbalancedPosting::class);
        $this->expectExceptionMessage('Posting debits and credits do not balance for USD.');

        new Posting([
            $this->entry('account-a', EntryDirection::Debit, 1_000),
            $this->entry('account-b', EntryDirection::Credit, 999),
        ]);
    }

    #[Test]
    public function it_does_not_net_different_currencies_against_each_other(): void
    {
        $this->expectException(UnbalancedPosting::class);
        $this->expectExceptionMessage('Posting debits and credits do not balance for USD.');

        new Posting([
            $this->entry('account-a', EntryDirection::Debit, 100),
            $this->entry('account-b', EntryDirection::Credit, 100, 'EUR'),
        ]);
    }

    #[Test]
    public function it_requires_at_least_two_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A posting must contain at least two entries.');

        new Posting([$this->entry('account-a', EntryDirection::Debit, 100)]);
    }

    #[Test]
    public function it_rejects_zero_value_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Journal entry amount must be greater than zero.');

        $this->entry('account-a', EntryDirection::Debit, 0);
    }

    #[Test]
    public function it_rejects_an_empty_account_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Journal entry account ID cannot be empty.');

        $this->entry('', EntryDirection::Debit, 100);
    }

    private function entry(
        string $accountId,
        EntryDirection $direction,
        int $minorAmount,
        string $currency = 'USD',
    ): JournalEntry {
        $currencyValue = $currency === 'USD' ? $this->usd : Currency::fromCode($currency);

        return new JournalEntry(
            $accountId,
            $direction,
            Money::ofMinor($minorAmount, $currencyValue),
        );
    }
}
