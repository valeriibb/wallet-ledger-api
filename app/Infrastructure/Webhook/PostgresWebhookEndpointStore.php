<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Webhook\Exception\WebhookEndpointNotFound;
use App\Application\Webhook\Port\WebhookEndpointStore;
use App\Application\Webhook\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PostgresWebhookEndpointStore implements WebhookEndpointStore
{
    public function listForOwner(string $ownerId): array
    {
        return array_values(DB::table('webhook_endpoints')
            ->where('owner_id', $ownerId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): WebhookEndpoint => $this->toEndpoint($row))
            ->values()
            ->all());
    }

    public function create(string $ownerId, string $url, string $encryptedSecret, array $events): WebhookEndpoint
    {
        $id = (string) Str::uuid7();
        $now = now();
        DB::table('webhook_endpoints')->insert([
            'id' => $id,
            'owner_id' => $ownerId,
            'url' => $url,
            'secret' => $encryptedSecret,
            'events' => json_encode($events, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findForOwner($ownerId, $id);
    }

    public function update(
        string $ownerId,
        string $endpointId,
        ?string $url,
        ?array $events,
        ?bool $isActive,
    ): WebhookEndpoint {
        $changes = ['updated_at' => now()];

        if ($url !== null) {
            $changes['url'] = $url;
        }

        if ($events !== null) {
            $changes['events'] = json_encode($events, JSON_THROW_ON_ERROR);
        }

        if ($isActive !== null) {
            $changes['is_active'] = $isActive;
        }

        $updated = DB::table('webhook_endpoints')
            ->where('id', $endpointId)
            ->where('owner_id', $ownerId)
            ->update($changes);

        if ($updated === 0 && ! DB::table('webhook_endpoints')
            ->where('id', $endpointId)
            ->where('owner_id', $ownerId)
            ->exists()) {
            throw new WebhookEndpointNotFound;
        }

        return $this->findForOwner($ownerId, $endpointId);
    }

    public function delete(string $ownerId, string $endpointId): void
    {
        $deleted = DB::table('webhook_endpoints')
            ->where('id', $endpointId)
            ->where('owner_id', $ownerId)
            ->delete();

        if ($deleted === 0) {
            throw new WebhookEndpointNotFound;
        }
    }

    private function findForOwner(string $ownerId, string $endpointId): WebhookEndpoint
    {
        $row = DB::table('webhook_endpoints')
            ->where('id', $endpointId)
            ->where('owner_id', $ownerId)
            ->first();

        if (! is_object($row)) {
            throw new WebhookEndpointNotFound;
        }

        return $this->toEndpoint($row);
    }

    private function toEndpoint(object $row): WebhookEndpoint
    {
        /** @var array<string, mixed> $data */
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $url = $data['url'] ?? null;
        $eventsJson = $data['events'] ?? null;
        $isActive = filter_var($data['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $createdAt = $data['created_at'] ?? null;

        if (! is_string($id)
            || ! is_string($url)
            || ! is_string($eventsJson)
            || $isActive === null
            || ! is_string($createdAt)) {
            throw new RuntimeException('The database returned an invalid webhook endpoint.');
        }

        $events = json_decode($eventsJson, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($events) || array_filter($events, static fn (mixed $event): bool => ! is_string($event)) !== []) {
            throw new RuntimeException('The database returned invalid webhook events.');
        }

        /** @var list<string> $events */
        return new WebhookEndpoint($id, $url, $events, $isActive, $createdAt);
    }
}
