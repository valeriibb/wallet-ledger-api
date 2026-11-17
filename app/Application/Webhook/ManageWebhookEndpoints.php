<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Application\Webhook\Port\SecretCipher;
use App\Application\Webhook\Port\WebhookEndpointStore;

final readonly class ManageWebhookEndpoints
{
    public function __construct(
        private WebhookEndpointStore $store,
        private WebhookUrlGuard $urlGuard,
        private SecretCipher $cipher,
    ) {}

    /** @return list<WebhookEndpoint> */
    public function list(string $ownerId): array
    {
        return $this->store->listForOwner($ownerId);
    }

    /** @param list<string> $events */
    public function create(string $ownerId, string $url, array $events): CreatedWebhookEndpoint
    {
        $this->urlGuard->assertSafe($url);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $endpoint = $this->store->create($ownerId, $url, $this->cipher->encrypt($secret), $events);

        return new CreatedWebhookEndpoint($endpoint, $secret);
    }

    /** @param list<string>|null $events */
    public function update(
        string $ownerId,
        string $endpointId,
        ?string $url,
        ?array $events,
        ?bool $isActive,
    ): WebhookEndpoint {
        if ($url !== null) {
            $this->urlGuard->assertSafe($url);
        }

        return $this->store->update($ownerId, $endpointId, $url, $events, $isActive);
    }

    public function delete(string $ownerId, string $endpointId): void
    {
        $this->store->delete($ownerId, $endpointId);
    }
}
