<?php

declare(strict_types=1);

namespace App\Application\Cash\Exception;

use RuntimeException;

final class PaymentDeclined extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The payment provider declined the operation.');
    }
}
