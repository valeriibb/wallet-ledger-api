<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Webhook\Port\HostResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    #[Test]
    public function an_owner_can_create_list_update_and_delete_a_webhook(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $created = $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://hooks.example.com/wallet',
            'events' => ['transfer.completed', 'deposit.completed'],
        ])->assertCreated()
            ->assertJsonPath('data.url', 'https://hooks.example.com/wallet')
            ->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');
        $secret = $created->json('data.signing_secret');
        self::assertIsString($id);
        self::assertIsString($secret);
        self::assertSame(43, strlen($secret));
        $encrypted = DB::table('webhook_endpoints')->where('id', $id)->value('secret');
        self::assertIsString($encrypted);
        self::assertNotSame($secret, $encrypted);
        self::assertSame($secret, Crypt::decryptString($encrypted));

        $this->getJson('/v1/webhook-endpoints')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.signing_secret');

        $this->patchJson("/v1/webhook-endpoints/{$id}", [
            'events' => ['fee.completed'],
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.events.0', 'fee.completed')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/v1/webhook-endpoints/{$id}")->assertNoContent();
        self::assertDatabaseMissing('webhook_endpoints', ['id' => $id]);
    }

    #[Test]
    public function endpoints_are_scoped_to_the_authenticated_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://hooks.example.com/wallet',
            'events' => ['transfer.completed'],
        ])->assertCreated()->json('data.id');
        self::assertIsString($id);
        Sanctum::actingAs($otherUser);

        $this->getJson('/v1/webhook-endpoints')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/v1/webhook-endpoints/{$id}", ['is_active' => false])->assertNotFound();
        $this->deleteJson("/v1/webhook-endpoints/{$id}")->assertNotFound();
    }

    #[Test]
    public function unsafe_urls_and_unknown_event_types_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://127.0.0.1/admin',
            'events' => ['transfer.completed'],
        ])->assertUnprocessable()
            ->assertJsonPath('detail', 'A webhook URL cannot target a private or reserved address.');

        $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://hooks.example.com/wallet',
            'events' => ['password.stolen'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('events.0');

        self::assertDatabaseCount('webhook_endpoints', 0);
    }

    #[Test]
    public function only_the_endpoint_owner_can_replay_a_delivery(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($owner);
        $endpointId = $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://hooks.example.com/wallet',
            'events' => ['transfer.completed'],
        ])->assertCreated()->json('data.id');
        self::assertIsString($endpointId);
        $eventId = (string) Str::uuid7();
        $deliveryId = (string) Str::uuid7();
        DB::table('outbox_events')->insert([
            'id' => $eventId,
            'aggregate_type' => 'ledger_transaction',
            'aggregate_id' => (string) Str::uuid7(),
            'event_type' => 'transfer.completed',
            'payload' => '{}',
        ]);
        DB::table('webhook_deliveries')->insert([
            'id' => $deliveryId,
            'endpoint_id' => $endpointId,
            'event_id' => $eventId,
            'status' => 'dead_letter',
            'attempts' => 5,
            'next_retry_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($otherUser);
        $this->postJson("/v1/webhook-deliveries/{$deliveryId}/replay")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->postJson("/v1/webhook-deliveries/{$deliveryId}/replay")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        self::assertDatabaseHas('webhook_deliveries', [
            'id' => $deliveryId,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }
}
