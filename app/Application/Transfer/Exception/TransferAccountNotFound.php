<?php

declare(strict_types=1);

namespace App\Application\Transfer\Exception;

use RuntimeException;

final class TransferAccountNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A transfer account was not found.');
    }
}
