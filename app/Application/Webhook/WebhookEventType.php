<?php

declare(strict_types=1);

namespace App\Application\Webhook;

enum WebhookEventType: string
{
    case TransferCompleted = 'transfer.completed';
    case DepositCompleted = 'deposit.completed';
    case WithdrawalCompleted = 'withdrawal.completed';
    case ReversalCompleted = 'reversal.completed';
    case FeeCompleted = 'fee.completed';
    case ConversionCompleted = 'conversion.completed';
}
