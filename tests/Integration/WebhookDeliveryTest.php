<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Outbox\Port\OutboxWriter;
use App\Application\Outbox\RelayOutboxEvents;
use App\Application\Webhook\DeliverWebhooks;
use App\Application\Webhook\Port\HostResolver;
use App\Application\Webhook\Port\WebhookHttpClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeWebhookHttpClient;
use Tests\TestCase;

final class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_schedules_signs_and_delivers_a_subscribed_event(): void
    {
        $client = new FakeWebhookHttpClient;
        $this->app->instance(WebhookHttpClient::class, $client);
        $this->publicDns();
        $this->createEndpoint();
        $eventId = app(OutboxWriter::class)->append(
            'ledger_transaction',
            (string) Str::uuid7(),
            'transfer.completed',
            ['amount' => 100],
        );

        app(RelayOutboxEvents::class)->execute();
        $result = app(DeliverWebhooks::class)->execute();

        self::assertSame(1, $result->delivered);
        self::assertCount(1, $client->requests);
        $request = $client->requests[0];
        self::assertSame('https://hooks.example.com/events', $request['url']);
        self::assertSame($eventId, $request['headers']['X-Webhook-Event-Id']);
        self::assertStringStartsWith('v1=', $request['headers']['X-Webhook-Signature']);
        self::assertDatabaseHas('webhook_deliveries', [
            'event_id' => $eventId,
            'status' => 'delivered',
            'attempts' => 1,
            'response_code' => 200,
        ]);
    }

    #[Test]
    public function repeated_failures_use_backoff_and_end_in_dead_letter(): void
    {
        $this->app->instance(WebhookHttpClient::class, new FakeWebhookHttpClient(503));
        $this->publicDns();
        $this->createEndpoint();
        app(OutboxWriter::class)->append(
            'ledger_transaction',
            (string) Str::uuid7(),
            'transfer.completed',
            ['amount' => 100],
        );
        app(RelayOutboxEvents::class)->execute();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            app(DeliverWebhooks::class)->execute();

            if ($attempt < 5) {
                self::assertDatabaseHas('webhook_deliveries', ['status' => 'pending', 'attempts' => $attempt]);
                DB::table('webhook_deliveries')->update(['next_retry_at' => now()->subSecond()]);
            }
        }

        self::assertDatabaseHas('webhook_deliveries', [
            'status' => 'dead_letter',
            'attempts' => 5,
            'response_code' => 503,
        ]);
    }

    private function createEndpoint(): void
    {
        $owner = User::factory()->create();
        DB::table('webhook_endpoints')->insert([
            'id' => (string) Str::uuid7(),
            'owner_id' => $owner->id,
            'url' => 'https://hooks.example.com/events',
            'secret' => Crypt::encryptString('test-secret'),
            'events' => json_encode(['transfer.completed'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function publicDns(): void
    {
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }
}
