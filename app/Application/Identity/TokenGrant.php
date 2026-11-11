<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class TokenGrant
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public string $plainTextToken,
    ) {}
}
