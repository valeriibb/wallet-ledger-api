<?php

declare(strict_types=1);

namespace App\Infrastructure\Transfer;

use App\Application\Ledger\Port\LedgerStore;
use App\Application\Transfer\Exception\TransferAccountNotFound;
use App\Application\Transfer\Exception\TransferNotAllowed;
use App\Application\Transfer\Port\TransferStore;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\CreateTransferPosting;
use App\Domain\Transfer\TransferAccount;
use App\Infrastructure\Database\PostgresConcurrencyRetrier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class PostgresTransferStore implements TransferStore
{
    public function __construct(
        private LedgerStore $ledgerStore,
        private CreateTransferPosting $createPosting,
        private PostgresConcurrencyRetrier $retrier,
    ) {}

    public function transfer(
        string $actorId,
        string $sourceAccountId,
        string $destinationAccountId,
        Money $money,
        ?string $reference,
    ): string {
        return $this->retrier->run(fn (): string => DB::transaction(function () use (
            $actorId,
            $sourceAccountId,
            $destinationAccountId,
            $money,
            $reference,
        ): string {
            $accountIds = array_values(array_unique([$sourceAccountId, $destinationAccountId]));
            sort($accountIds, SORT_STRING);

            $rows = DB::table('accounts')
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($rows->count() !== count($accountIds)) {
                throw new TransferAccountNotFound;
            }

            $sourceRow = $rows->get($sourceAccountId);
            $destinationRow = $rows->get($destinationAccountId);

            if (! is_object($sourceRow) || ! is_object($destinationRow)) {
                throw new TransferAccountNotFound;
            }

            $source = $this->toTransferAccount($sourceRow);
            $destination = $this->toTransferAccount($destinationRow);

            if ($source->ownerId !== $actorId) {
                throw new TransferNotAllowed;
            }

            $posting = $this->createPosting->execute($source, $destination, $money);

            return $this->ledgerStore->post($posting, LedgerTransactionType::Transfer, $reference);
        }));
    }

    private function toTransferAccount(object $row): TransferAccount
    {
        /** @var array<string, mixed> $data */
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $ownerId = $data['owner_id'] ?? null;
        $type = $data['type'] ?? null;
        $currency = $data['currency'] ?? null;
        $status = $data['status'] ?? null;

        if (! is_string($id)
            || ($ownerId !== null && ! is_string($ownerId))
            || ! is_string($type)
            || ! is_string($currency)
            || ! is_string($status)) {
            throw new RuntimeException('The database returned an invalid account row.');
        }

        return new TransferAccount(
            $id,
            $ownerId,
            $type,
            Currency::fromCode($currency),
            $status,
        );
    }
}
