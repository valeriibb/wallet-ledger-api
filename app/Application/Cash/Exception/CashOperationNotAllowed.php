<?php

declare(strict_types=1);

namespace App\Application\Cash\Exception;

use RuntimeException;

final class CashOperationNotAllowed extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
