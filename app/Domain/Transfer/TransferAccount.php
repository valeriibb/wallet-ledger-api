<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Shared\ValueObject\Currency;

final readonly class TransferAccount
{
    public function __construct(
        public string $id,
        public ?string $ownerId,
        public string $type,
        public Currency $currency,
        public string $status,
    ) {}
}
