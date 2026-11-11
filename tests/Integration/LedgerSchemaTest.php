<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_valid_ledger_entry_can_be_created(): void
    {
        $entry = $this->createEntry();

        self::assertDatabaseHas('ledger_entries', [
            'id' => $entry['id'],
            'transaction_id' => $entry['transaction_id'],
            'account_id' => $entry['account_id'],
            'direction' => 'credit',
            'amount' => 100,
            'currency' => 'USD',
            'balance_after' => 100,
        ]);
    }

    #[Test]
    public function a_ledger_entry_cannot_be_updated(): void
    {
        $entry = $this->createEntry();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Ledger entries are immutable; create a reversal instead.');

        DB::table('ledger_entries')->where('id', $entry['id'])->update(['amount' => 200]);
    }

    #[Test]
    public function a_ledger_entry_cannot_be_deleted(): void
    {
        $entry = $this->createEntry();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Ledger entries are immutable; create a reversal instead.');

        DB::table('ledger_entries')->where('id', $entry['id'])->delete();
    }

    #[Test]
    public function ledger_entries_cannot_be_truncated(): void
    {
        $this->createEntry();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Ledger entries are immutable; create a reversal instead.');

        DB::table('ledger_entries')->truncate();
    }

    #[Test]
    public function a_ledger_entry_amount_must_be_positive(): void
    {
        $entry = $this->entryAttributes($this->createFixture());
        $entry['amount'] = 0;

        $this->expectException(QueryException::class);

        DB::table('ledger_entries')->insert($entry);
    }

    #[Test]
    public function a_ledger_entry_direction_must_be_debit_or_credit(): void
    {
        $entry = $this->entryAttributes($this->createFixture());
        $entry['id'] = (string) Str::uuid7();
        $entry['direction'] = 'refund';

        $this->expectException(QueryException::class);

        DB::table('ledger_entries')->insert($entry);
    }

    #[Test]
    public function a_ledger_entry_currency_must_match_its_account(): void
    {
        $entry = $this->entryAttributes($this->createFixture());
        $entry['currency'] = 'EUR';

        $this->expectException(QueryException::class);

        DB::table('ledger_entries')->insert($entry);
    }

    /**
     * @return array{id: string, transaction_id: string, account_id: string}
     */
    private function createEntry(): array
    {
        $ids = $this->createFixture();

        DB::table('ledger_entries')->insert($this->entryAttributes($ids));

        return $ids;
    }

    /**
     * @return array{id: string, transaction_id: string, account_id: string}
     */
    private function createFixture(): array
    {
        $user = User::factory()->create();
        $accountId = (string) Str::uuid7();
        $transactionId = (string) Str::uuid7();
        $entryId = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $accountId,
            'owner_id' => $user->id,
            'type' => 'user_wallet',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        DB::table('ledger_transactions')->insert([
            'id' => $transactionId,
            'type' => 'deposit',
            'status' => 'posted',
        ]);

        return [
            'id' => $entryId,
            'transaction_id' => $transactionId,
            'account_id' => $accountId,
        ];
    }

    /**
     * @param  array{id: string, transaction_id: string, account_id: string}  $ids
     * @return array{id: string, transaction_id: string, account_id: string, direction: string, amount: int, currency: string, balance_after: int}
     */
    private function entryAttributes(array $ids): array
    {
        return [
            'id' => $ids['id'],
            'transaction_id' => $ids['transaction_id'],
            'account_id' => $ids['account_id'],
            'direction' => 'credit',
            'amount' => 100,
            'currency' => 'USD',
            'balance_after' => 100,
        ];
    }
}
