<?php

declare(strict_types=1);

namespace App\Application\Idempotency\Exception;

use RuntimeException;

final class IdempotencyRequestInProgress extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A request with this idempotency key is still processing.');
    }
}
