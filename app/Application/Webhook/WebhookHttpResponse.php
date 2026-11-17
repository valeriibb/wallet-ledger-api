<?php

declare(strict_types=1);

namespace App\Application\Webhook;

final readonly class WebhookHttpResponse
{
    public function __construct(public int $statusCode) {}

    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
