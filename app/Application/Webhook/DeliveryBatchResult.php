<?php

declare(strict_types=1);

namespace App\Application\Webhook;

final readonly class DeliveryBatchResult
{
    public function __construct(public int $processed, public int $delivered, public int $failed) {}
}
