<?php

declare(strict_types=1);

namespace App\Infrastructure\Ledger;

use App\Application\Ledger\Port\LedgerStore;
use App\Application\Outbox\Port\OutboxWriter;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\Exception\InsufficientFunds;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OverflowException;
use RuntimeException;

final class PostgresLedgerStore implements LedgerStore
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function post(
        Posting $posting,
        LedgerTransactionType $type,
        ?string $reference = null,
        ?string $reversalOf = null,
    ): string {
        return DB::transaction(function () use ($posting, $type, $reference, $reversalOf): string {
            /** @var list<JournalEntry> $entries */
            $entries = $posting->entries();
            $accountIds = array_values(array_unique(array_map(
                static fn (JournalEntry $entry): string => $entry->accountId(),
                $entries,
            )));
            sort($accountIds, SORT_STRING);

            $storedBalances = DB::table('account_balances')
                ->whereIn('account_id', $accountIds)
                ->orderBy('account_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('account_id');

            if ($storedBalances->count() !== count($accountIds)) {
                throw new RuntimeException('Every ledger account must have an account balance row.');
            }

            /** @var array<string, int> $runningBalances */
            $runningBalances = [];
            /** @var array<string, bool> $allowNegative */
            $allowNegative = [];

            foreach ($storedBalances as $accountId => $storedBalance) {
                $balance = filter_var($storedBalance->balance, FILTER_VALIDATE_INT);
                $negativeAllowed = filter_var(
                    $storedBalance->allow_negative,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );

                if ($balance === false || $negativeAllowed === null) {
                    throw new RuntimeException('The database returned an invalid account balance row.');
                }

                $runningBalances[(string) $accountId] = $balance;
                $allowNegative[(string) $accountId] = $negativeAllowed;
            }

            $transactionId = (string) Str::uuid7();
            $ledgerEntries = [];

            foreach ($entries as $entry) {
                $accountId = $entry->accountId();
                $currentBalance = $runningBalances[$accountId];
                $amount = $entry->amount()->minorAmount();

                $nextBalance = $entry->direction() === EntryDirection::Credit
                    ? $this->addToBalance($currentBalance, $amount)
                    : $this->subtractFromBalance($currentBalance, $amount);

                if (! $allowNegative[$accountId] && $nextBalance < 0) {
                    throw InsufficientFunds::forAccount($accountId);
                }

                $runningBalances[$accountId] = $nextBalance;
                $ledgerEntries[] = [
                    'id' => (string) Str::uuid7(),
                    'transaction_id' => $transactionId,
                    'account_id' => $accountId,
                    'direction' => $entry->direction()->value,
                    'amount' => $amount,
                    'currency' => $entry->currency()->code(),
                    'balance_after' => $nextBalance,
                ];
            }

            DB::table('ledger_transactions')->insert([
                'id' => $transactionId,
                'type' => $type->value,
                'status' => 'posted',
                'reversal_of' => $reversalOf,
                'reference' => $reference,
            ]);

            DB::table('ledger_entries')->insert($ledgerEntries);

            foreach ($runningBalances as $accountId => $balance) {
                DB::table('account_balances')
                    ->where('account_id', $accountId)
                    ->update([
                        'balance' => $balance,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
            }

            $this->outbox->append(
                'ledger_transaction',
                $transactionId,
                $type->value.'.completed',
                [
                    'transaction_id' => $transactionId,
                    'type' => $type->value,
                    'reference' => $reference,
                    'reversal_of' => $reversalOf,
                ],
            );

            return $transactionId;
        });
    }

    private function addToBalance(int $balance, int $amount): int
    {
        if ($balance > PHP_INT_MAX - $amount) {
            throw new OverflowException('Account balance exceeds the integer limit.');
        }

        return $balance + $amount;
    }

    private function subtractFromBalance(int $balance, int $amount): int
    {
        if ($balance < PHP_INT_MIN + $amount) {
            throw new OverflowException('Account balance is below the integer limit.');
        }

        return $balance - $amount;
    }
}
