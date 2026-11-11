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

final class AccountReadingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function account_list_contains_only_the_authenticated_users_wallets(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceUsd = $this->createWallet($alice, 'USD', 1250);
        $aliceEur = $this->createWallet($alice, 'EUR', 700);
        $this->createWallet($bob, 'GBP', 900);
        Sanctum::actingAs($alice);

        $response = $this->getJson('/v1/accounts')->assertOk();

        $response
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $aliceUsd, 'currency' => 'USD'])
            ->assertJsonFragment(['id' => $aliceEur, 'currency' => 'EUR'])
            ->assertJsonMissing(['currency' => 'GBP']);
    }

    #[Test]
    public function an_owner_can_read_their_wallet_balance(): void
    {
        $alice = User::factory()->create();
        $accountId = $this->createWallet($alice, 'USD', 1250);
        Sanctum::actingAs($alice);

        $this->getJson("/v1/accounts/{$accountId}/balance")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'account_id' => $accountId,
                    'currency' => 'USD',
                    'balance' => 1250,
                ],
            ]);
    }

    #[Test]
    public function another_user_cannot_read_a_wallet_balance(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceWallet = $this->createWallet($alice, 'USD', 1250);
        Sanctum::actingAs($bob);

        $this->getJson("/v1/accounts/{$aliceWallet}/balance")->assertForbidden();
    }

    #[Test]
    public function authentication_is_required_to_list_accounts_and_read_balances(): void
    {
        $alice = User::factory()->create();
        $accountId = $this->createWallet($alice, 'USD', 0);

        $this->getJson('/v1/accounts')->assertUnauthorized();
        $this->getJson("/v1/accounts/{$accountId}/balance")->assertUnauthorized();
    }

    #[Test]
    public function statement_uses_a_stable_cursor_without_duplicate_entries(): void
    {
        $alice = User::factory()->create();
        $accountId = $this->createWallet($alice, 'USD', 0);
        $entryIds = $this->createStatement($accountId, 'USD', [100, 200, 300]);
        Sanctum::actingAs($alice);

        $firstPage = $this->getJson("/v1/accounts/{$accountId}/entries?limit=2")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $firstPage
            ->assertJsonPath('data.0.id', $entryIds[2])
            ->assertJsonPath('data.1.id', $entryIds[1]);
        $cursor = $firstPage->json('meta.next_cursor');
        self::assertIsString($cursor);

        $secondPage = $this->getJson(
            "/v1/accounts/{$accountId}/entries?limit=2&cursor=".rawurlencode($cursor),
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.next_cursor', null);

        $secondPage->assertJsonPath('data.0.id', $entryIds[0]);
    }

    #[Test]
    public function another_user_cannot_read_a_wallet_statement(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceWallet = $this->createWallet($alice, 'USD', 0);
        Sanctum::actingAs($bob);

        $this->getJson("/v1/accounts/{$aliceWallet}/entries")->assertForbidden();
    }

    #[Test]
    public function an_invalid_statement_cursor_is_rejected(): void
    {
        $alice = User::factory()->create();
        $accountId = $this->createWallet($alice, 'USD', 0);
        Sanctum::actingAs($alice);

        $this->getJson("/v1/accounts/{$accountId}/entries?cursor=invalid")
            ->assertUnprocessable()
            ->assertJsonPath('errors.cursor.0', 'The cursor is invalid.');
    }

    private function createWallet(User $owner, string $currency, int $balance): string
    {
        $accountId = (string) Str::uuid7();

        DB::transaction(function () use ($accountId, $owner, $currency, $balance): void {
            DB::table('accounts')->insert([
                'id' => $accountId,
                'owner_id' => $owner->id,
                'type' => 'user_wallet',
                'currency' => $currency,
                'status' => 'active',
            ]);
            DB::table('account_balances')->insert([
                'account_id' => $accountId,
                'balance' => $balance,
                'version' => 0,
                'allow_negative' => false,
            ]);
        });

        return $accountId;
    }

    /**
     * @param  list<int>  $amounts
     * @return list<string>
     */
    private function createStatement(string $accountId, string $currency, array $amounts): array
    {
        $systemAccountId = (string) Str::uuid7();
        DB::table('accounts')->insert([
            'id' => $systemAccountId,
            'owner_id' => null,
            'type' => 'system_cash',
            'currency' => $currency,
            'status' => 'active',
        ]);
        DB::table('account_balances')->insert([
            'account_id' => $systemAccountId,
            'balance' => -array_sum($amounts),
            'version' => 0,
            'allow_negative' => true,
        ]);

        $entryIds = [];
        $runningBalance = 0;

        foreach ($amounts as $position => $amount) {
            $transactionId = (string) Str::uuid7();
            $entryId = (string) Str::uuid7();
            $runningBalance += $amount;
            $createdAt = now()->subMinutes(count($amounts) - $position);

            DB::table('ledger_transactions')->insert([
                'id' => $transactionId,
                'type' => 'deposit',
                'status' => 'posted',
                'created_at' => $createdAt,
            ]);
            DB::table('ledger_entries')->insert([
                [
                    'id' => $entryId,
                    'transaction_id' => $transactionId,
                    'account_id' => $accountId,
                    'direction' => 'credit',
                    'amount' => $amount,
                    'currency' => $currency,
                    'balance_after' => $runningBalance,
                    'created_at' => $createdAt,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'transaction_id' => $transactionId,
                    'account_id' => $systemAccountId,
                    'direction' => 'debit',
                    'amount' => $amount,
                    'currency' => $currency,
                    'balance_after' => -$runningBalance,
                    'created_at' => $createdAt,
                ],
            ]);
            $entryIds[] = $entryId;
        }

        DB::table('account_balances')->where('account_id', $accountId)->update([
            'balance' => $runningBalance,
        ]);

        return $entryIds;
    }
}
