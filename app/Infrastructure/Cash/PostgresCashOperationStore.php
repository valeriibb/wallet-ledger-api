<?php

declare(strict_types=1);

namespace App\Infrastructure\Cash;

use App\Application\Cash\Exception\CashOperationNotAllowed;
use App\Application\Cash\Port\CashOperationStore;
use App\Application\Ledger\Port\LedgerStore;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Database\PostgresConcurrencyRetrier;
use Illuminate\Support\Facades\DB;

final readonly class PostgresCashOperationStore implements CashOperationStore
{
    public function __construct(
        private LedgerStore $ledgerStore,
        private PostgresConcurrencyRetrier $retrier,
    ) {}

    public function deposit(
        string $actorId,
        string $walletId,
        string $systemCashAccountId,
        Money $money,
        string $providerReference,
    ): string {
        return $this->post(
            $actorId,
            $walletId,
            $systemCashAccountId,
            $money,
            $providerReference,
            LedgerTransactionType::Deposit,
        );
    }

    public function withdraw(
        string $actorId,
        string $walletId,
        string $systemCashAccountId,
        Money $money,
        string $providerReference,
    ): string {
        return $this->post(
            $actorId,
            $walletId,
            $systemCashAccountId,
            $money,
            $providerReference,
            LedgerTransactionType::Withdrawal,
        );
    }

    private function post(
        string $actorId,
        string $walletId,
        string $systemCashAccountId,
        Money $money,
        string $providerReference,
        LedgerTransactionType $type,
    ): string {
        $transaction = fn (): string => DB::transaction(function () use (
            $actorId,
            $walletId,
            $systemCashAccountId,
            $money,
            $providerReference,
            $type,
        ): string {
            $accountIds = [$walletId, $systemCashAccountId];
            sort($accountIds, SORT_STRING);

            $accounts = DB::table('accounts')
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $wallet = $accounts->get($walletId);
            $systemCash = $accounts->get($systemCashAccountId);

            if (! is_object($wallet) || ! is_object($systemCash)) {
                throw new CashOperationNotAllowed('A cash operation account was not found.');
            }

            /** @var array<string, mixed> $walletData */
            $walletData = (array) $wallet;
            /** @var array<string, mixed> $systemData */
            $systemData = (array) $systemCash;
            $currency = $money->currency()->code();

            if (($walletData['owner_id'] ?? null) !== $actorId
                || ($walletData['type'] ?? null) !== 'user_wallet') {
                throw new CashOperationNotAllowed('The wallet does not belong to the authenticated user.');
            }

            if (($walletData['status'] ?? null) !== 'active'
                || ($systemData['status'] ?? null) !== 'active') {
                throw new CashOperationNotAllowed('Both cash operation accounts must be active.');
            }

            if (($walletData['currency'] ?? null) !== $currency
                || ($systemData['currency'] ?? null) !== $currency) {
                throw new CashOperationNotAllowed('The operation currency must match both accounts.');
            }

            if (($systemData['type'] ?? null) !== 'system_cash') {
                throw new CashOperationNotAllowed('The counter account must be system cash.');
            }

            $deposit = $type === LedgerTransactionType::Deposit;
            $posting = new Posting([
                new JournalEntry(
                    $deposit ? $systemCashAccountId : $walletId,
                    EntryDirection::Debit,
                    $money,
                ),
                new JournalEntry(
                    $deposit ? $walletId : $systemCashAccountId,
                    EntryDirection::Credit,
                    $money,
                ),
            ]);

            return $this->ledgerStore->post($posting, $type, $providerReference);
        });

        if (DB::connection()->transactionLevel() > 0) {
            return $transaction();
        }

        return $this->retrier->run($transaction);
    }
}
