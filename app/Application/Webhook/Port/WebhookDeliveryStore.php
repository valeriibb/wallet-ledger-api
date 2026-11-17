<?php

declare(strict_types=1);

namespace App\Application\Webhook\Port;

use App\Application\Outbox\OutboxEvent;
use App\Application\Webhook\WebhookDelivery;

interface WebhookDeliveryStore
{
    public function schedule(OutboxEvent $event): void;

    /** @return list<WebhookDelivery> */
    public function claimDue(int $limit): array;

    public function markDelivered(string $id, int $responseCode): void;

    public function markFailed(string $id, int $attempt, ?int $responseCode, string $error): void;

    public function replay(string $ownerId, string $deliveryId): void;
}
