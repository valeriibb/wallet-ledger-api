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

        $response = $this->postJson('/v1/transfers', [
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

        $this->postJson('/v1/transfers', $this->payload($sourceId, $destinationId, 100))
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

        $this->postJson('/v1/transfers', $this->payload($sourceId, $frozenDestinationId, 50))
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

        $this->postJson('/v1/transfers', $this->payload($sourceId, $destinationId, 50))
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'The source account does not have enough funds.');

        self::assertDatabaseCount('ledger_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        $this->assertBalance($sourceId, 40);
        $this->assertBalance($destinationId, 0);
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

    private function assertBalance(string $accountId, int $balance): void
    {
        self::assertDatabaseHas('account_balances', [
            'account_id' => $accountId,
            'balance' => $balance,
            'version' => 0,
        ]);
    }
}
