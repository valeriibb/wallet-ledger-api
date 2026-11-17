<?php

declare(strict_types=1);

namespace App\Infrastructure\Fee;

use App\Application\Fee\Exception\FeeNotAllowed;
use App\Application\Fee\Port\FeeStore;
use App\Application\Ledger\Port\LedgerStore;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Database\PostgresConcurrencyRetrier;
use Illuminate\Support\Facades\DB;

final readonly class PostgresFeeStore implements FeeStore
{
    public function __construct(
        private LedgerStore $ledgerStore,
        private PostgresConcurrencyRetrier $retrier,
    ) {}

    public function charge(
        string $actorId,
        string $walletId,
        string $systemFeesAccountId,
        Money $money,
        ?string $reference,
    ): string {
        $transaction = fn (): string => DB::transaction(function () use (
            $actorId,
            $walletId,
            $systemFeesAccountId,
            $money,
            $reference,
        ): string {
            $accountIds = [$walletId, $systemFeesAccountId];
            sort($accountIds, SORT_STRING);

            $accounts = DB::table('accounts')
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $wallet = $accounts->get($walletId);
            $systemFees = $accounts->get($systemFeesAccountId);

            if (! is_object($wallet) || ! is_object($systemFees)) {
                throw new FeeNotAllowed('A fee account was not found.');
            }

            /** @var array<string, mixed> $walletData */
            $walletData = (array) $wallet;
            /** @var array<string, mixed> $systemData */
            $systemData = (array) $systemFees;
            $currency = $money->currency()->code();

            if (($walletData['owner_id'] ?? null) !== $actorId
                || ($walletData['type'] ?? null) !== 'user_wallet') {
                throw new FeeNotAllowed('The wallet does not belong to the fee owner.');
            }

            if (($walletData['status'] ?? null) !== 'active'
                || ($systemData['status'] ?? null) !== 'active') {
                throw new FeeNotAllowed('Both fee accounts must be active.');
            }

            if (($walletData['currency'] ?? null) !== $currency
                || ($systemData['currency'] ?? null) !== $currency) {
                throw new FeeNotAllowed('The fee currency must match both accounts.');
            }

            if (($systemData['type'] ?? null) !== 'system_fees') {
                throw new FeeNotAllowed('The counter account must be system fees.');
            }

            return $this->ledgerStore->post(new Posting([
                new JournalEntry($walletId, EntryDirection::Debit, $money),
                new JournalEntry($systemFeesAccountId, EntryDirection::Credit, $money),
            ]), LedgerTransactionType::Fee, $reference);
        });

        if (DB::connection()->transactionLevel() > 0) {
            return $transaction();
        }

        return $this->retrier->run($transaction);
    }
}
