<?php

declare(strict_types=1);

namespace App\Application\Ledger\Port;

use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;

interface LedgerStore
{
    public function post(
        Posting $posting,
        LedgerTransactionType $type,
        ?string $reference = null,
        ?string $reversalOf = null,
    ): string;
}
