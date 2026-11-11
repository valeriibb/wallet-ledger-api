<?php

declare(strict_types=1);

namespace App\Application\Transfer\Exception;

use RuntimeException;

final class TransferNotAllowed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The source wallet does not belong to the authenticated user.');
    }
}
