<?php

declare(strict_types=1);

namespace App\Application\Webhook\Port;

use App\Application\Webhook\WebhookHttpResponse;

interface WebhookHttpClient
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body): WebhookHttpResponse;
}
