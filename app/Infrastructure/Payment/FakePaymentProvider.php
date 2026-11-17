<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Payment\Port\PaymentProvider;
use App\Application\Payment\ProviderPayment;
use App\Application\Payment\ProviderPaymentStatus;
use App\Domain\Shared\ValueObject\Money;

final class FakePaymentProvider implements PaymentProvider
{
    public function charge(
        string $userId,
        Money $amount,
        string $idempotencyKey,
    ): ProviderPayment {
        return new ProviderPayment(
            providerReference: 'fake_'.substr(hash('sha256', $userId.'|'.$idempotencyKey), 0, 32),
            status: ProviderPaymentStatus::Confirmed,
        );
    }

    public function payout(
        string $userId,
        Money $amount,
        string $idempotencyKey,
    ): ProviderPayment {
        return new ProviderPayment(
            providerReference: 'fake_payout_'.substr(hash('sha256', $userId.'|'.$idempotencyKey), 0, 32),
            status: ProviderPaymentStatus::Confirmed,
        );
    }
}
