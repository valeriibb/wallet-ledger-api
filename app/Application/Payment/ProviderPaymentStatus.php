<?php

declare(strict_types=1);

namespace App\Application\Payment;

enum ProviderPaymentStatus: string
{
    case Confirmed = 'confirmed';
    case Declined = 'declined';
}
