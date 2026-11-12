<?php

declare(strict_types=1);

namespace App\Application\Idempotency\Exception;

use RuntimeException;

final class IdempotencyKeyReused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The idempotency key was already used with a different request.');
    }
}
