<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class StatementPage
{
    /** @param list<StatementEntry> $entries */
    public function __construct(
        public array $entries,
        public ?string $nextCursor,
    ) {}
}
