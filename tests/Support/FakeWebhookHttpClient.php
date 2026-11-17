<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Webhook\Port\WebhookHttpClient;
use App\Application\Webhook\WebhookHttpResponse;

final class FakeWebhookHttpClient implements WebhookHttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    public function __construct(public int $statusCode = 200) {}

    public function post(string $url, array $headers, string $body): WebhookHttpResponse
    {
        $this->requests[] = compact('url', 'headers', 'body');

        return new WebhookHttpResponse($this->statusCode);
    }
}
