<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Outbox\OutboxEvent;
use App\Application\Outbox\Port\EventPublisher;
use RuntimeException;

final readonly class FileRecordingEventPublisher implements EventPublisher
{
    public function __construct(private string $path) {}

    public function publish(OutboxEvent $event): void
    {
        $written = file_put_contents($this->path, $event->id."\n", FILE_APPEND | LOCK_EX);

        if ($written === false) {
            throw new RuntimeException('Could not record the published event.');
        }
    }
}
