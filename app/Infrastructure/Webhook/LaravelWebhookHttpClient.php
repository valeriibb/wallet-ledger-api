<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Webhook\Port\WebhookHttpClient;
use App\Application\Webhook\WebhookHttpResponse;
use Illuminate\Support\Facades\Http;

final class LaravelWebhookHttpClient implements WebhookHttpClient
{
    public function post(string $url, array $headers, string $body): WebhookHttpResponse
    {
        $response = Http::withHeaders($headers)
            ->connectTimeout(3)
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->post($url);

        return new WebhookHttpResponse($response->status());
    }
}
