<?php

declare(strict_types=1);

namespace App\Application\Reversal\Port;

interface ReversalStore
{
    public function reverse(string $actorId, string $originalTransactionId): string;
}
