<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Application\Outbox\Port\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PostgresOutboxWriter implements OutboxWriter
{
    public function append(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('outbox_events')->insert([
            'id' => $id,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}
