<?php

declare(strict_types=1);

namespace App\Application\Payment\Port;

use App\Application\Payment\ProviderPayment;
use App\Domain\Shared\ValueObject\Money;

interface PaymentProvider
{
    public function charge(
        string $userId,
        Money $amount,
        string $idempotencyKey,
    ): ProviderPayment;

    public function payout(
        string $userId,
        Money $amount,
        string $idempotencyKey,
    ): ProviderPayment;
}
