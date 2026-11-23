<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion;

use App\Application\Account\GetSystemAccount;
use App\Application\Conversion\Exception\ConversionAccountNotFound;
use App\Application\Conversion\Exception\ConversionNotAllowed;
use App\Application\Conversion\Port\ConversionStore;
use App\Application\Ledger\Port\LedgerStore;
use App\Domain\Account\SystemAccountType;
use App\Domain\Conversion\ConversionAccount;
use App\Domain\Conversion\CreateConversionPosting;
use App\Domain\Currency\ConvertedMoney;
use App\Domain\Currency\ExchangeRate;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Database\PostgresConcurrencyRetrier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class PostgresConversionStore implements ConversionStore
{
    public function __construct(
        private LedgerStore $ledgerStore,
        private GetSystemAccount $systemAccounts,
        private CreateConversionPosting $createPosting,
        private PostgresConcurrencyRetrier $retrier,
    ) {}

    public function convert(
        string $actorId,
        string $sourceAccountId,
        string $destinationAccountId,
        Money $input,
        ConvertedMoney $output,
        ExchangeRate $rate,
        ?string $reference,
    ): string {
        $sourceFx = $this->systemAccounts->execute(SystemAccountType::Fx, $input->currency());
        $destinationFx = $this->systemAccounts->execute(SystemAccountType::Fx, $output->money->currency());

        $transaction = fn (): string => DB::transaction(function () use (
            $actorId,
            $sourceAccountId,
            $destinationAccountId,
            $input,
            $output,
            $rate,
            $reference,
            $sourceFx,
            $destinationFx,
        ): string {
            $accountIds = array_values(array_unique([
                $sourceAccountId,
                $destinationAccountId,
                $sourceFx->id,
                $destinationFx->id,
            ]));
            sort($accountIds, SORT_STRING);

            $rows = DB::table('accounts')
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($rows->count() !== count($accountIds)) {
                throw new ConversionAccountNotFound;
            }

            $source = $this->account($rows->get($sourceAccountId));
            $destination = $this->account($rows->get($destinationAccountId));
            $lockedSourceFx = $this->account($rows->get($sourceFx->id));
            $lockedDestinationFx = $this->account($rows->get($destinationFx->id));

            if ($source->ownerId !== $actorId || $destination->ownerId !== $actorId) {
                throw new ConversionNotAllowed;
            }

            $posting = $this->createPosting->execute(
                $source,
                $destination,
                $lockedSourceFx,
                $lockedDestinationFx,
                $input,
                $output->money,
            );

            return $this->ledgerStore->post(
                $posting,
                LedgerTransactionType::Conversion,
                $reference,
                metadata: [
                    'exchange_rate' => $rate->decimal(),
                    'rate_source' => $rate->source(),
                    'rate_valid_from' => $rate->validFrom(),
                    'input_minor_amount' => $input->minorAmount(),
                    'input_currency' => $input->currency()->code(),
                    'exact_output_minor_amount' => $output->exactMinorAmount,
                    'output_minor_amount' => $output->money->minorAmount(),
                    'output_currency' => $output->money->currency()->code(),
                    'rounding_mode' => 'half_up',
                    'rounding_difference_minor' => $output->roundingDifference,
                ],
            );
        });

        if (DB::connection()->transactionLevel() > 0) {
            return $transaction();
        }

        return $this->retrier->run($transaction);
    }

    private function account(mixed $row): ConversionAccount
    {
        if (! is_object($row)) {
            throw new ConversionAccountNotFound;
        }

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

        return new ConversionAccount(
            $id,
            $ownerId,
            $type,
            Currency::fromCode($currency),
            $status,
        );
    }
}
