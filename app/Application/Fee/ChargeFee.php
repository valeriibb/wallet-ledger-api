<?php

declare(strict_types=1);

namespace App\Application\Fee;

use App\Application\Account\GetSystemAccount;
use App\Application\Fee\Port\FeeStore;
use App\Application\Idempotency\IdempotencyResult;
use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Idempotency\StoredResponse;
use App\Domain\Account\SystemAccountType;
use App\Domain\Shared\ValueObject\Money;

final readonly class ChargeFee
{
    public function __construct(
        private GetSystemAccount $systemAccounts,
        private FeeStore $store,
        private IdempotencyExecutor $idempotency,
    ) {}

    public function execute(
        string $actorId,
        string $walletId,
        Money $money,
        string $key,
        ?string $reference = null,
    ): IdempotencyResult {
        $systemFees = $this->systemAccounts->execute(SystemAccountType::Fees, $money->currency());
        $hash = hash('sha256', implode('|', [
            'fee',
            $walletId,
            (string) $money->minorAmount(),
            $money->currency()->code(),
            $reference ?? '',
        ]));

        return $this->idempotency->execute(
            $actorId,
            $key,
            $hash,
            function () use ($actorId, $walletId, $systemFees, $money, $reference): StoredResponse {
                $transactionId = $this->store->charge(
                    $actorId,
                    $walletId,
                    $systemFees->id,
                    $money,
                    $reference,
                );

                return new StoredResponse(201, ['data' => [
                    'transaction_id' => $transactionId,
                    'account_id' => $walletId,
                    'amount' => $money->minorAmount(),
                    'currency' => $money->currency()->code(),
                    'status' => 'posted',
                ]], $transactionId);
            },
        );
    }
}
