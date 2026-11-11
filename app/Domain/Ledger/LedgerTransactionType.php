<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum LedgerTransactionType: string
{
    case Transfer = 'transfer';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Conversion = 'conversion';
    case Reversal = 'reversal';
    case Fee = 'fee';
}
