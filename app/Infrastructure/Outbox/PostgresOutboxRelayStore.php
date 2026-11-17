<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Application\Outbox\OutboxEvent;
use App\Application\Outbox\Port\OutboxRelayStore;
use App\Application\Outbox\RelayBatchResult;
use Closure;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

final class PostgresOutboxRelayStore implements OutboxRelayStore
{
    public function relayBatch(int $limit, Closure $publish): RelayBatchResult
    {
        return DB::transaction(function () use ($limit, $publish): RelayBatchResult {
            $rows = DB::table('outbox_events')
                ->whereNull('published_at')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            $published = 0;
            $failed = 0;

            foreach ($rows as $row) {
                $event = $this->toEvent($row);

                DB::table('outbox_events')
                    ->where('id', $event->id)
                    ->update(['attempts' => DB::raw('attempts + 1')]);

                try {
                    $publish($event);
                    DB::table('outbox_events')
                        ->where('id', $event->id)
                        ->update(['published_at' => now()]);
                    $published++;
                } catch (Throwable) {
                    $failed++;
                }
            }

            return new RelayBatchResult($rows->count(), $published, $failed);
        });
    }

    private function toEvent(object $row): OutboxEvent
    {
        /** @var array<string, mixed> $data */
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $aggregateType = $data['aggregate_type'] ?? null;
        $aggregateId = $data['aggregate_id'] ?? null;
        $eventType = $data['event_type'] ?? null;
        $payloadJson = $data['payload'] ?? null;
        $occurredAt = $data['occurred_at'] ?? null;
        $attempts = filter_var($data['attempts'] ?? null, FILTER_VALIDATE_INT);

        if (! is_string($id)
            || ! is_string($aggregateType)
            || ! is_string($aggregateId)
            || ! is_string($eventType)
            || ! is_string($payloadJson)
            || ! is_string($occurredAt)
            || $attempts === false) {
            throw new RuntimeException('The database returned an invalid outbox event.');
        }

        try {
            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The database returned invalid outbox JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('The outbox payload must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return new OutboxEvent(
            $id,
            $aggregateType,
            $aggregateId,
            $eventType,
            $payload,
            $occurredAt,
            $attempts,
        );
    }
}
