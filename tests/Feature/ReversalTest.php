<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ReversalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_can_reverse_a_transfer_with_opposite_entries(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($sender);
        $originalId = $this->transfer($sourceId, $destinationId, 125);

        $response = $this->reverse($originalId)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.reversal_of', $originalId)
            ->assertJsonPath('data.status', 'posted');

        $reversalId = $response->json('data.transaction_id');
        self::assertIsString($reversalId);
        self::assertDatabaseHas('ledger_transactions', [
            'id' => $originalId,
            'status' => 'reversed',
        ]);
        self::assertDatabaseHas('ledger_transactions', [
            'id' => $reversalId,
            'type' => 'reversal',
            'status' => 'posted',
            'reversal_of' => $originalId,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $reversalId,
            'account_id' => $sourceId,
            'direction' => 'credit',
            'amount' => 125,
            'balance_after' => 500,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $reversalId,
            'account_id' => $destinationId,
            'direction' => 'debit',
            'amount' => 125,
            'balance_after' => 0,
        ]);
        $this->assertBalance($sourceId, 500, 2);
        $this->assertBalance($destinationId, 0, 2);
    }

    #[Test]
    public function the_same_key_replays_but_a_second_reversal_is_rejected(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($sender);
        $originalId = $this->transfer($sourceId, $destinationId, 100);
        $key = (string) Str::uuid();

        $first = $this->reverse($originalId, $key)->assertCreated();
        $second = $this->reverse($originalId, $key)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        self::assertEquals($first->json(), $second->json());

        $this->reverse($originalId)
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'The transaction has already been reversed.');

        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'reversal')->count());
        $this->assertBalance($sourceId, 500, 2);
        $this->assertBalance($destinationId, 0, 2);
    }

    #[Test]
    public function a_reversal_transaction_cannot_be_reversed(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($sender);
        $originalId = $this->transfer($sourceId, $destinationId, 100);
        $reversalId = $this->reverse($originalId)->assertCreated()->json('data.transaction_id');
        self::assertIsString($reversalId);

        $this->reverse($reversalId)
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'A reversal transaction cannot be reversed.');

        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'reversal')->count());
    }

    #[Test]
    public function a_transfer_recipient_cannot_reverse_the_senders_transfer(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($sender);
        $originalId = $this->transfer($sourceId, $destinationId, 100);
        Sanctum::actingAs($recipient);

        $this->reverse($originalId)
            ->assertForbidden()
            ->assertJsonPath('detail', 'You are not allowed to reverse this transaction.');

        self::assertDatabaseHas('ledger_transactions', ['id' => $originalId, 'status' => 'posted']);
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'reversal')->count());
    }

    #[Test]
    public function a_reversal_is_rolled_back_if_it_would_make_a_wallet_negative(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($owner, 100);
        $destinationId = $this->createWallet($recipient, 0);
        Sanctum::actingAs($owner);
        $depositId = $this->cashMovement('/v1/deposits', $sourceId, 100);
        $this->transfer($sourceId, $destinationId, 200);

        $this->reverse($depositId)
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'The reversal would make a wallet balance negative.');

        self::assertDatabaseHas('ledger_transactions', ['id' => $depositId, 'status' => 'posted']);
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'reversal')->count());
        $this->assertBalance($sourceId, 0, 2);
    }

    /** @return TestResponse<Response> */
    private function reverse(string $transactionId, ?string $key = null): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson("/v1/transactions/{$transactionId}/reversal");
    }

    private function transfer(string $sourceId, string $destinationId, int $amount): string
    {
        $transactionId = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/transfers', [
                'from_account_id' => $sourceId,
                'to_account_id' => $destinationId,
                'amount' => $amount,
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->json('data.transaction_id');
        self::assertIsString($transactionId);

        return $transactionId;
    }

    private function cashMovement(string $uri, string $walletId, int $amount): string
    {
        $transactionId = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($uri, [
                'account_id' => $walletId,
                'amount' => $amount,
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->json('data.transaction_id');
        self::assertIsString($transactionId);

        return $transactionId;
    }

    private function createWallet(User $owner, int $balance): string
    {
        $accountId = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $accountId,
            'owner_id' => $owner->id,
            'type' => 'user_wallet',
            'currency' => 'USD',
            'status' => 'active',
        ]);
        DB::table('account_balances')->insert([
            'account_id' => $accountId,
            'balance' => $balance,
            'version' => 0,
            'allow_negative' => false,
        ]);

        return $accountId;
    }

    private function assertBalance(string $accountId, int $balance, int $version): void
    {
        self::assertDatabaseHas('account_balances', [
            'account_id' => $accountId,
            'balance' => $balance,
            'version' => $version,
        ]);
    }
}
