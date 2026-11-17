<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Webhook\Port\SecretCipher;
use Illuminate\Support\Facades\Crypt;

final class LaravelSecretCipher implements SecretCipher
{
    public function encrypt(string $value): string
    {
        return Crypt::encryptString($value);
    }

    public function decrypt(string $value): string
    {
        return Crypt::decryptString($value);
    }
}
