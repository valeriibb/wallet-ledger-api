<?php

declare(strict_types=1);

namespace App\Application\Outbox;

final readonly class RelayBatchResult
{
    public function __construct(
        public int $processed,
        public int $published,
        public int $failed,
    ) {}
}
