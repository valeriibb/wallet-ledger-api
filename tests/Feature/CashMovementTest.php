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
use Tests\TestCase;

final class CashMovementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_can_deposit_money_into_an_active_wallet(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 0);
        Sanctum::actingAs($owner);

        $response = $this->cashRequest('/v1/deposits', $walletId, 2500)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.account_id', $walletId)
            ->assertJsonPath('data.amount', 2500)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'posted');

        $transactionId = $response->json('data.transaction_id');
        $providerReference = $response->json('data.provider_reference');
        self::assertIsString($transactionId);
        self::assertIsString($providerReference);
        $systemCashId = $this->systemCashId();

        self::assertDatabaseHas('ledger_transactions', [
            'id' => $transactionId,
            'type' => 'deposit',
            'reference' => $providerReference,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $systemCashId,
            'direction' => 'debit',
            'amount' => 2500,
            'balance_after' => -2500,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $walletId,
            'direction' => 'credit',
            'amount' => 2500,
            'balance_after' => 2500,
        ]);
        $this->assertBalance($walletId, 2500, 1);
        $this->assertBalance($systemCashId, -2500, 1);
    }

    #[Test]
    public function an_owner_can_withdraw_money_from_an_active_wallet(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 3000);
        Sanctum::actingAs($owner);

        $response = $this->cashRequest('/v1/withdrawals', $walletId, 1000)
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $transactionId = $response->json('data.transaction_id');
        $providerReference = $response->json('data.provider_reference');
        self::assertIsString($transactionId);
        self::assertIsString($providerReference);
        self::assertStringStartsWith('fake_payout_', $providerReference);
        $systemCashId = $this->systemCashId();

        self::assertDatabaseHas('ledger_transactions', [
            'id' => $transactionId,
            'type' => 'withdrawal',
            'reference' => $providerReference,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $walletId,
            'direction' => 'debit',
            'balance_after' => 2000,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $systemCashId,
            'direction' => 'credit',
            'balance_after' => 1000,
        ]);
        $this->assertBalance($walletId, 2000, 1);
        $this->assertBalance($systemCashId, 1000, 1);
    }

    #[Test]
    public function insufficient_funds_do_not_create_a_local_withdrawal(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 400);
        Sanctum::actingAs($owner);

        $this->cashRequest('/v1/withdrawals', $walletId, 500)
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'The wallet does not have enough funds.');

        self::assertDatabaseCount('ledger_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        $this->assertBalance($walletId, 400);
    }

    #[Test]
    public function a_frozen_wallet_rejects_a_cash_movement_and_rolls_back_local_changes(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 0, status: 'frozen');
        Sanctum::actingAs($owner);

        $this->cashRequest('/v1/deposits', $walletId, 500)
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'Both cash operation accounts must be active.');

        self::assertDatabaseCount('ledger_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        $this->assertBalance($walletId, 0);
    }

    #[Test]
    public function a_user_cannot_make_a_cash_movement_for_another_users_wallet(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $walletId = $this->createWallet($owner, 1000);
        Sanctum::actingAs($attacker);

        $this->cashRequest('/v1/withdrawals', $walletId, 100)
            ->assertForbidden();

        self::assertDatabaseCount('ledger_transactions', 0);
        $this->assertBalance($walletId, 1000);
    }

    #[Test]
    public function repeating_a_deposit_replays_the_response_without_crediting_twice(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 0);
        $key = (string) Str::uuid();
        Sanctum::actingAs($owner);

        $first = $this->cashRequest('/v1/deposits', $walletId, 700, $key)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false');
        $second = $this->cashRequest('/v1/deposits', $walletId, 700, $key)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        self::assertEquals($first->json(), $second->json());
        self::assertDatabaseCount('ledger_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
        $this->assertBalance($walletId, 700, 1);
    }

    #[Test]
    public function an_idempotency_key_is_required_for_a_cash_movement(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 0);
        Sanctum::actingAs($owner);

        $this->postJson('/v1/deposits', $this->payload($walletId, 500))
            ->assertUnprocessable()
            ->assertJsonPath('errors.idempotency_key.0', 'The idempotency key field is required.');

        self::assertDatabaseCount('ledger_transactions', 0);
    }

    private function cashRequest(string $uri, string $walletId, int $amount, ?string $key = null): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson($uri, $this->payload($walletId, $amount));
    }

    /** @return array{account_id: string, amount: int, currency: string} */
    private function payload(string $walletId, int $amount): array
    {
        return [
            'account_id' => $walletId,
            'amount' => $amount,
            'currency' => 'USD',
        ];
    }

    private function createWallet(User $owner, int $balance, string $status = 'active'): string
    {
        $accountId = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $accountId,
            'owner_id' => $owner->id,
            'type' => 'user_wallet',
            'currency' => 'USD',
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

    private function systemCashId(): string
    {
        $id = DB::table('accounts')
            ->where('type', 'system_cash')
            ->where('currency', 'USD')
            ->value('id');

        self::assertIsString($id);

        return $id;
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
