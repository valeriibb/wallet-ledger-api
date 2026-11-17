<?php

declare(strict_types=1);

namespace App\Application\Cash;

use App\Application\Account\GetSystemAccount;
use App\Application\Cash\Exception\PaymentDeclined;
use App\Application\Cash\Port\CashOperationStore;
use App\Application\Idempotency\IdempotencyResult;
use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Idempotency\StoredResponse;
use App\Application\Payment\Port\PaymentProvider;
use App\Application\Payment\ProviderPaymentStatus;
use App\Domain\Account\SystemAccountType;
use App\Domain\Shared\ValueObject\Money;

final readonly class WithdrawFunds
{
    public function __construct(
        private PaymentProvider $provider,
        private GetSystemAccount $systemAccounts,
        private CashOperationStore $store,
        private IdempotencyExecutor $idempotency,
    ) {}

    public function execute(string $userId, string $walletId, Money $money, string $key): IdempotencyResult
    {
        $payment = $this->provider->payout($userId, $money, $key);

        if ($payment->status !== ProviderPaymentStatus::Confirmed) {
            throw new PaymentDeclined;
        }

        $systemCash = $this->systemAccounts->execute(SystemAccountType::Cash, $money->currency());
        $hash = hash('sha256', "withdrawal|{$walletId}|{$money->minorAmount()}|{$money->currency()->code()}");

        return $this->idempotency->execute($userId, $key, $hash, function () use (
            $userId,
            $walletId,
            $systemCash,
            $money,
            $payment,
        ): StoredResponse {
            $transactionId = $this->store->withdraw(
                $userId,
                $walletId,
                $systemCash->id,
                $money,
                $payment->providerReference,
            );

            return new StoredResponse(201, ['data' => [
                'transaction_id' => $transactionId,
                'account_id' => $walletId,
                'amount' => $money->minorAmount(),
                'currency' => $money->currency()->code(),
                'status' => 'posted',
                'provider_reference' => $payment->providerReference,
            ]], $transactionId);
        });
    }
}
