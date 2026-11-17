<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Outbox\OutboxEvent;
use App\Application\Webhook\Exception\WebhookDeliveryNotFound;
use App\Application\Webhook\Port\SecretCipher;
use App\Application\Webhook\Port\WebhookDeliveryStore;
use App\Application\Webhook\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PostgresWebhookDeliveryStore implements WebhookDeliveryStore
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(private SecretCipher $cipher) {}

    public function schedule(OutboxEvent $event): void
    {
        $endpoints = DB::table('webhook_endpoints')
            ->where('is_active', true)
            ->whereJsonContains('events', $event->eventType)
            ->pluck('id');
        $now = now();

        foreach ($endpoints as $endpointId) {
            if (! is_string($endpointId)) {
                throw new RuntimeException('The database returned an invalid webhook endpoint ID.');
            }

            DB::table('webhook_deliveries')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'endpoint_id' => $endpointId,
                'event_id' => $event->id,
                'status' => 'pending',
                'attempts' => 0,
                'next_retry_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function claimDue(int $limit): array
    {
        return DB::transaction(function () use ($limit): array {
            $ids = DB::table('webhook_deliveries')
                ->where(function ($query): void {
                    $query->where(function ($pending): void {
                        $pending->where('status', 'pending')->where('next_retry_at', '<=', now());
                    })->orWhere(function ($stale): void {
                        $stale->where('status', 'processing')->where('locked_until', '<=', now());
                    });
                })
                ->orderBy('next_retry_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return [];
            }

            DB::table('webhook_deliveries')->whereIn('id', $ids)->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'locked_until' => now()->addMinutes(5),
                'updated_at' => now(),
            ]);

            return array_values(DB::table('webhook_deliveries as deliveries')
                ->join('webhook_endpoints as endpoints', 'endpoints.id', '=', 'deliveries.endpoint_id')
                ->join('outbox_events as events', 'events.id', '=', 'deliveries.event_id')
                ->whereIn('deliveries.id', $ids)
                ->orderBy('deliveries.id')
                ->get([
                    'deliveries.id', 'deliveries.attempts', 'events.id as event_id',
                    'events.event_type', 'events.payload', 'endpoints.url', 'endpoints.secret',
                ])
                ->map(fn (object $row): WebhookDelivery => $this->toDelivery($row))
                ->all());
        });
    }

    public function markDelivered(string $id, int $responseCode): void
    {
        DB::table('webhook_deliveries')->where('id', $id)->update([
            'status' => 'delivered',
            'response_code' => $responseCode,
            'locked_until' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    public function markFailed(string $id, int $attempt, ?int $responseCode, string $error): void
    {
        $dead = $attempt >= self::MAX_ATTEMPTS;
        $delays = [60, 300, 1800, 7200, 86400];

        DB::table('webhook_deliveries')->where('id', $id)->update([
            'status' => $dead ? 'dead_letter' : 'pending',
            'response_code' => $responseCode,
            'next_retry_at' => $dead ? now() : now()->addSeconds($delays[$attempt - 1] ?? 86400),
            'locked_until' => null,
            'last_error' => mb_substr($error, 0, 2000),
            'updated_at' => now(),
        ]);
    }

    public function replay(string $ownerId, string $deliveryId): void
    {
        $updated = DB::table('webhook_deliveries as deliveries')
            ->join('webhook_endpoints as endpoints', 'endpoints.id', '=', 'deliveries.endpoint_id')
            ->where('deliveries.id', $deliveryId)
            ->where('endpoints.owner_id', $ownerId)
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'response_code' => null,
                'next_retry_at' => now(),
                'locked_until' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new WebhookDeliveryNotFound;
        }
    }

    private function toDelivery(object $row): WebhookDelivery
    {
        /** @var array<string, mixed> $data */
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $eventId = $data['event_id'] ?? null;
        $eventType = $data['event_type'] ?? null;
        $url = $data['url'] ?? null;
        $secret = $data['secret'] ?? null;
        $payloadJson = $data['payload'] ?? null;
        $attempt = filter_var($data['attempts'] ?? null, FILTER_VALIDATE_INT);

        if (! is_string($id) || ! is_string($eventId) || ! is_string($eventType)
            || ! is_string($url) || ! is_string($secret) || ! is_string($payloadJson) || $attempt === false) {
            throw new RuntimeException('The database returned an invalid webhook delivery.');
        }

        $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new RuntimeException('The webhook event payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return new WebhookDelivery($id, $eventId, $eventType, $url, $this->cipher->decrypt($secret), $payload, $attempt);
    }
}
