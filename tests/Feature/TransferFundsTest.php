<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransferFundsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_can_transfer_money_between_active_wallets_atomically(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 25);
        Sanctum::actingAs($sender);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/transfers', [
                'from_account_id' => $sourceId,
                'to_account_id' => $destinationId,
                'amount' => 125,
                'currency' => 'USD',
                'reference' => 'invoice-42',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.amount', 125)
            ->assertJsonPath('data.currency', 'USD');

        $transactionId = $response->json('data.transaction_id');
        self::assertIsString($transactionId);
        self::assertDatabaseHas('ledger_transactions', [
            'id' => $transactionId,
            'type' => 'transfer',
            'reference' => 'invoice-42',
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $sourceId,
            'direction' => 'debit',
            'amount' => 125,
            'balance_after' => 375,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $destinationId,
            'direction' => 'credit',
            'amount' => 125,
            'balance_after' => 150,
        ]);
    }

    #[Test]
    public function a_user_cannot_debit_another_users_wallet(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($owner, 500);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($attacker);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/transfers', $this->payload($sourceId, $destinationId, 100))
            ->assertForbidden();

        self::assertDatabaseCount('ledger_transactions', 0);
        $this->assertBalance($sourceId, 500);
        $this->assertBalance($destinationId, 0);
    }

    #[Test]
    public function rejected_domain_rules_roll_back_the_whole_transfer(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 100);
        $frozenDestinationId = $this->createWallet($recipient, 0, status: 'frozen');
        Sanctum::actingAs($sender);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/transfers', $this->payload($sourceId, $frozenDestinationId, 50))
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'Both accounts must be active.');

        self::assertDatabaseCount('ledger_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        $this->assertBalance($sourceId, 100);
        $this->assertBalance($frozenDestinationId, 0);
    }

    #[Test]
    public function insufficient_funds_roll_back_the_whole_transfer(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 40);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($sender);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/transfers', $this->payload($sourceId, $destinationId, 50))
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'The source account does not have enough funds.');

        self::assertDatabaseCount('ledger_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        $this->assertBalance($sourceId, 40);
        $this->assertBalance($destinationId, 0);
    }

    #[Test]
    public function repeating_the_same_key_and_request_replays_the_original_response(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        $key = (string) Str::uuid();
        $payload = $this->payload($sourceId, $destinationId, 100);
        Sanctum::actingAs($sender);

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/transfers', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false');
        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/transfers', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        self::assertEquals($first->json(), $second->json());
        self::assertDatabaseCount('ledger_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
        $this->assertBalance($sourceId, 400, 1);
        $this->assertBalance($destinationId, 100, 1);
        self::assertDatabaseHas('idempotency_keys', [
            'owner_id' => $sender->id,
            'key' => $key,
            'status' => 'completed',
            'response_code' => 201,
        ]);
    }

    #[Test]
    public function reusing_a_key_with_a_different_request_is_rejected(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        $key = (string) Str::uuid();
        Sanctum::actingAs($sender);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/transfers', $this->payload($sourceId, $destinationId, 100))
            ->assertCreated();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/transfers', $this->payload($sourceId, $destinationId, 200))
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'The idempotency key was already used with a different request.');

        self::assertDatabaseCount('ledger_transactions', 1);
        $this->assertBalance($sourceId, 400, 1);
        $this->assertBalance($destinationId, 100, 1);
    }

    #[Test]
    public function an_idempotency_key_is_required_for_a_transfer(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($sender);

        $this->postJson('/v1/transfers', $this->payload($sourceId, $destinationId, 100))
            ->assertUnprocessable()
            ->assertJsonPath('errors.idempotency_key.0', 'The idempotency key field is required.');

        self::assertDatabaseCount('ledger_transactions', 0);
    }

    #[Test]
    public function an_expired_idempotency_key_can_start_a_new_operation(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        $key = (string) Str::uuid();
        $payload = $this->payload($sourceId, $destinationId, 100);
        Sanctum::actingAs($sender);

        $firstTransactionId = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/transfers', $payload)
            ->assertCreated()
            ->json('data.transaction_id');

        DB::table('idempotency_keys')
            ->where('owner_id', $sender->id)
            ->where('key', $key)
            ->update(['expires_at' => now()->subSecond()]);

        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/transfers', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false');

        self::assertNotSame($firstTransactionId, $second->json('data.transaction_id'));
        self::assertDatabaseCount('ledger_transactions', 2);
        self::assertDatabaseCount('ledger_entries', 4);
        $this->assertBalance($sourceId, 300, 2);
        $this->assertBalance($destinationId, 200, 2);
    }

    /** @return array{from_account_id: string, to_account_id: string, amount: int, currency: string} */
    private function payload(string $sourceId, string $destinationId, int $amount): array
    {
        return [
            'from_account_id' => $sourceId,
            'to_account_id' => $destinationId,
            'amount' => $amount,
            'currency' => 'USD',
        ];
    }

    private function createWallet(
        User $owner,
        int $balance,
        string $currency = 'USD',
        string $status = 'active',
    ): string {
        $accountId = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $accountId,
            'owner_id' => $owner->id,
            'type' => 'user_wallet',
            'currency' => $currency,
            'status' => $status,
        ]);
        DB::table('account_balances')->insert([
            'account_id' => $accountId,
            'balance' => $balance,
            'version' => 0,
            'allow_negative' => false,
        ]);

        return $accountId;
    }

    private function assertBalance(string $accountId, int $balance, int $version = 0): void
    {
        self::assertDatabaseHas('account_balances', [
            'account_id' => $accountId,
            'balance' => $balance,
            'version' => $version,
        ]);
    }
}
