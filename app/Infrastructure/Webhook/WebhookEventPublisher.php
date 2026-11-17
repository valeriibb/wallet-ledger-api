<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Outbox\OutboxEvent;
use App\Application\Outbox\Port\EventPublisher;
use App\Application\Webhook\Port\WebhookDeliveryStore;

final readonly class WebhookEventPublisher implements EventPublisher
{
    public function __construct(private WebhookDeliveryStore $deliveries) {}

    public function publish(OutboxEvent $event): void
    {
        $this->deliveries->schedule($event);
    }
}
