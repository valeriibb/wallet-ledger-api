<?php

declare(strict_types=1);

namespace App\Application\Webhook\Port;

interface SecretCipher
{
    public function encrypt(string $value): string;

    public function decrypt(string $value): string;
}
