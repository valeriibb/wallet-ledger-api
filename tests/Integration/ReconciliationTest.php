<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Ledger\PostLedgerTransaction;
use App\Application\Reconciliation\RunReconciliation;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_balanced_ledger_produces_a_clean_report(): void
    {
        $this->postBalancedTransaction();

        $result = app(RunReconciliation::class)->execute();

        self::assertSame('clean', $result->status);
        self::assertSame(0, $result->discrepancyCount);
        self::assertSame([], $result->countsByKind);
        self::assertDatabaseHas('reconciliation_runs', ['id' => $result->runId, 'status' => 'clean']);
        self::assertDatabaseCount('reconciliation_discrepancies', 0);
    }

    #[Test]
    public function an_old_pending_outbox_event_is_reported_without_being_modified(): void
    {
        $this->postBalancedTransaction();
        $eventId = DB::table('outbox_events')->value('id');
        self::assertIsString($eventId);
        DB::table('outbox_events')->where('id', $eventId)->update(['occurred_at' => now()->subMinutes(20)]);

        $result = app(RunReconciliation::class)->execute(15);

        self::assertSame('discrepancies', $result->status);
        self::assertSame(1, $result->discrepancyCount);
        self::assertSame(['stale_outbox_event' => 1], $result->countsByKind);
        self::assertDatabaseHas('reconciliation_discrepancies', [
            'run_id' => $result->runId,
            'kind' => 'stale_outbox_event',
            'expected' => 0,
        ]);
        self::assertDatabaseHas('outbox_events', ['id' => $eventId, 'published_at' => null]);
    }

    #[Test]
    public function chaos_corruption_of_a_cached_balance_is_detected_but_not_repaired(): void
    {
        $this->postBalancedTransaction();
        $accountId = DB::table('accounts')->where('type', 'system_fees')->value('id');
        self::assertIsString($accountId);
        DB::table('account_balances')->where('account_id', $accountId)->update(['balance' => 101]);

        $result = app(RunReconciliation::class)->execute();

        self::assertSame('discrepancies', $result->status);
        self::assertSame(1, $result->discrepancyCount);
        self::assertSame(['account_balance_mismatch' => 1], $result->countsByKind);
        self::assertDatabaseHas('reconciliation_discrepancies', [
            'run_id' => $result->runId,
            'kind' => 'account_balance_mismatch',
            'account_id' => $accountId,
            'expected' => 100,
            'actual' => 101,
        ]);
        self::assertDatabaseHas('account_balances', [
            'account_id' => $accountId,
            'balance' => 101,
        ]);
        self::assertDatabaseCount('ledger_entries', 2);
    }

    private function postBalancedTransaction(): string
    {
        $sourceId = $this->createSystemAccount('system_cash', true);
        $destinationId = $this->createSystemAccount('system_fees', false);
        $money = Money::ofMinor(100, Currency::fromCode('USD'));

        return app(PostLedgerTransaction::class)->execute(
            new Posting([
                new JournalEntry($sourceId, EntryDirection::Debit, $money),
                new JournalEntry($destinationId, EntryDirection::Credit, $money),
            ]),
            LedgerTransactionType::Fee,
        );
    }

    private function createSystemAccount(string $type, bool $allowNegative): string
    {
        $id = (string) Str::uuid7();
        DB::table('accounts')->insert([
            'id' => $id,
            'owner_id' => null,
            'type' => $type,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        DB::table('account_balances')->insert([
            'account_id' => $id,
            'balance' => 0,
            'version' => 0,
            'allow_negative' => $allowNegative,
        ]);

        return $id;
    }
}
