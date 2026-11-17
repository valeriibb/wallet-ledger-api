<?php

declare(strict_types=1);

namespace App\Application\Outbox\Port;

use App\Application\Outbox\OutboxEvent;

interface EventPublisher
{
    public function publish(OutboxEvent $event): void;
}
