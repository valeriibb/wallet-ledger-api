<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ledger;

use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\ReversePosting;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReversePostingTest extends TestCase
{
    #[Test]
    public function it_flips_each_direction_and_preserves_accounts_and_amounts(): void
    {
        $money = Money::ofMinor(125, Currency::fromCode('USD'));

        $posting = (new ReversePosting)->execute([
            new JournalEntry('account-a', EntryDirection::Debit, $money),
            new JournalEntry('account-b', EntryDirection::Credit, $money),
        ]);

        $entries = $posting->entries();
        self::assertSame('account-a', $entries[0]->accountId());
        self::assertSame(EntryDirection::Credit, $entries[0]->direction());
        self::assertSame(125, $entries[0]->amount()->minorAmount());
        self::assertSame('account-b', $entries[1]->accountId());
        self::assertSame(EntryDirection::Debit, $entries[1]->direction());
        self::assertSame(125, $entries[1]->amount()->minorAmount());
    }
}
