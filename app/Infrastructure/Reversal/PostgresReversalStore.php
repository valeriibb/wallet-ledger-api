<?php

declare(strict_types=1);

namespace App\Infrastructure\Reversal;

use App\Application\Ledger\Port\LedgerStore;
use App\Application\Reversal\Exception\ReversalCannotBeReversed;
use App\Application\Reversal\Exception\ReversalNotAllowed;
use App\Application\Reversal\Exception\TransactionAlreadyReversed;
use App\Application\Reversal\Exception\TransactionNotFound;
use App\Application\Reversal\Port\ReversalStore;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\ReversePosting;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Database\PostgresConcurrencyRetrier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class PostgresReversalStore implements ReversalStore
{
    public function __construct(
        private LedgerStore $ledgerStore,
        private ReversePosting $reversePosting,
        private PostgresConcurrencyRetrier $retrier,
    ) {}

    public function reverse(string $actorId, string $originalTransactionId): string
    {
        $transaction = fn (): string => DB::transaction(function () use ($actorId, $originalTransactionId): string {
            $original = DB::table('ledger_transactions')
                ->where('id', $originalTransactionId)
                ->lockForUpdate()
                ->first();

            if (! is_object($original)) {
                throw new TransactionNotFound;
            }

            /** @var array<string, mixed> $transactionData */
            $transactionData = (array) $original;
            $type = $transactionData['type'] ?? null;
            $status = $transactionData['status'] ?? null;

            if ($type === LedgerTransactionType::Reversal->value) {
                throw new ReversalCannotBeReversed('A reversal transaction cannot be reversed.');
            }

            if ($status === 'reversed') {
                throw new TransactionAlreadyReversed('The transaction has already been reversed.');
            }

            if (! is_string($type) || $status !== 'posted') {
                throw new RuntimeException('The database returned an invalid ledger transaction.');
            }

            $entries = DB::table('ledger_entries')
                ->where('transaction_id', $originalTransactionId)
                ->orderBy('id')
                ->get();

            if ($entries->count() < 2) {
                throw new RuntimeException('A ledger transaction must contain at least two entries.');
            }

            $requiredOwnedDirection = $type === LedgerTransactionType::Deposit->value
                ? EntryDirection::Credit->value
                : EntryDirection::Debit->value;
            $authorized = DB::table('ledger_entries')
                ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_entries.transaction_id', $originalTransactionId)
                ->where('ledger_entries.direction', $requiredOwnedDirection)
                ->where('accounts.type', 'user_wallet')
                ->where('accounts.owner_id', $actorId)
                ->exists();

            if (! $authorized) {
                throw new ReversalNotAllowed;
            }

            $originalEntries = [];

            foreach ($entries as $entry) {
                /** @var array<string, mixed> $entryData */
                $entryData = (array) $entry;
                $accountId = $entryData['account_id'] ?? null;
                $direction = $entryData['direction'] ?? null;
                $amount = filter_var($entryData['amount'] ?? null, FILTER_VALIDATE_INT);
                $currency = $entryData['currency'] ?? null;

                if (! is_string($accountId)
                    || ! is_string($direction)
                    || $amount === false
                    || ! is_string($currency)) {
                    throw new RuntimeException('The database returned an invalid ledger entry.');
                }

                $originalEntries[] = new JournalEntry(
                    $accountId,
                    EntryDirection::from($direction),
                    Money::ofMinor($amount, Currency::fromCode($currency)),
                );
            }

            $reversalId = $this->ledgerStore->post(
                $this->reversePosting->execute($originalEntries),
                LedgerTransactionType::Reversal,
                reversalOf: $originalTransactionId,
            );

            DB::table('ledger_transactions')
                ->where('id', $originalTransactionId)
                ->update(['status' => 'reversed']);

            return $reversalId;
        });

        if (DB::connection()->transactionLevel() > 0) {
            return $transaction();
        }

        return $this->retrier->run($transaction);
    }
}
