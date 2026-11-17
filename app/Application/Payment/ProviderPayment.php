<?php

declare(strict_types=1);

namespace App\Application\Payment;

final readonly class ProviderPayment
{
    public function __construct(
        public string $providerReference,
        public ProviderPaymentStatus $status,
    ) {}
}
