<?php

declare(strict_types=1);

namespace App\Application\Webhook;

final class WebhookSigner
{
    public function sign(string $secret, string $eventId, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', "{$timestamp}.{$eventId}.{$body}", $secret);
    }
}
