<?php

declare(strict_types=1);

namespace App\Application\Webhook\Port;

use App\Application\Webhook\WebhookEndpoint;

interface WebhookEndpointStore
{
    /** @return list<WebhookEndpoint> */
    public function listForOwner(string $ownerId): array;

    /** @param list<string> $events */
    public function create(string $ownerId, string $url, string $encryptedSecret, array $events): WebhookEndpoint;

    /** @param list<string>|null $events */
    public function update(
        string $ownerId,
        string $endpointId,
        ?string $url,
        ?array $events,
        ?bool $isActive,
    ): WebhookEndpoint;

    public function delete(string $ownerId, string $endpointId): void;
}
