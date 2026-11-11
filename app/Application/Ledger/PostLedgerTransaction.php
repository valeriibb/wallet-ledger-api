<?php

declare(strict_types=1);

namespace App\Application\Ledger;

use App\Application\Ledger\Port\LedgerStore;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;

final readonly class PostLedgerTransaction
{
    public function __construct(private LedgerStore $ledgerStore) {}

    public function execute(Posting $posting, LedgerTransactionType $type, ?string $reference = null): string
    {
        return $this->ledgerStore->post($posting, $type, $reference);
    }
}
