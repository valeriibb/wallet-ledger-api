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

final class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_posts_four_balanced_entries_and_records_the_exact_rate(): void
    {
        $user = User::factory()->create();
        $usd = $this->createWallet($user, 'USD', 500);
        $eur = $this->createWallet($user, 'EUR', 10);
        Sanctum::actingAs($user);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/conversions', $this->payload($usd, $eur, 125));

        $response->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.input_amount', 125)
            ->assertJsonPath('data.input_currency', 'USD')
            ->assertJsonPath('data.output_amount', 115)
            ->assertJsonPath('data.output_currency', 'EUR')
            ->assertJsonPath('data.exchange_rate', '0.9200000000');

        $transactionId = $response->json('data.transaction_id');
        self::assertIsString($transactionId);
        self::assertSame(4, DB::table('ledger_entries')->where('transaction_id', $transactionId)->count());

        $sums = DB::table('ledger_entries')
            ->where('transaction_id', $transactionId)
            ->selectRaw("currency, SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS net")
            ->groupBy('currency')
            ->pluck('net', 'currency');
        $usdNet = filter_var($sums->get('USD'), FILTER_VALIDATE_INT);
        $eurNet = filter_var($sums->get('EUR'), FILTER_VALIDATE_INT);
        self::assertIsInt($usdNet);
        self::assertIsInt($eurNet);
        self::assertSame(0, $usdNet);
        self::assertSame(0, $eurNet);

        $metadataJson = DB::table('ledger_transactions')->where('id', $transactionId)->value('metadata');
        self::assertIsString($metadataJson);
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('0.9200000000', $metadata['exchange_rate']);
        self::assertSame('115.0000000000', $metadata['exact_output_minor_amount']);
        self::assertSame('0.0000000000', $metadata['rounding_difference_minor']);

        $this->assertBalance($usd, 375);
        $this->assertBalance($eur, 125);
        self::assertDatabaseHas('account_balances', ['balance' => 125, 'allow_negative' => true]);
        self::assertDatabaseHas('account_balances', ['balance' => -115, 'allow_negative' => true]);
        self::assertDatabaseCount('exchange_rates', 1);
    }

    #[Test]
    public function replay_does_not_convert_money_twice(): void
    {
        $user = User::factory()->create();
        $usd = $this->createWallet($user, 'USD', 500);
        $eur = $this->createWallet($user, 'EUR', 0);
        $key = (string) Str::uuid();
        $payload = $this->payload($usd, $eur, 100);
        Sanctum::actingAs($user);

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/v1/conversions', $payload)->assertCreated();
        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/v1/conversions', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        self::assertEquals($first->json(), $second->json());
        self::assertDatabaseCount('ledger_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 4);
        $this->assertBalance($usd, 400);
        $this->assertBalance($eur, 92);
    }

    #[Test]
    public function both_wallets_must_belong_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $usd = $this->createWallet($user, 'USD', 500);
        $eur = $this->createWallet($other, 'EUR', 0);
        Sanctum::actingAs($user);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/conversions', $this->payload($usd, $eur, 100))
            ->assertForbidden();

        self::assertDatabaseCount('ledger_transactions', 0);
        $this->assertBalance($usd, 500, 0);
        $this->assertBalance($eur, 0, 0);
    }

    /** @return array{from_account_id: string, to_account_id: string, amount: int, from_currency: string, to_currency: string} */
    private function payload(string $source, string $destination, int $amount): array
    {
        return [
            'from_account_id' => $source,
            'to_account_id' => $destination,
            'amount' => $amount,
            'from_currency' => 'USD',
            'to_currency' => 'EUR',
        ];
    }

    private function createWallet(User $owner, string $currency, int $balance): string
    {
        $id = (string) Str::uuid7();
        DB::table('accounts')->insert([
            'id' => $id,
            'owner_id' => $owner->id,
            'type' => 'user_wallet',
            'currency' => $currency,
            'status' => 'active',
        ]);
        DB::table('account_balances')->insert([
            'account_id' => $id,
            'balance' => $balance,
            'version' => 0,
            'allow_negative' => false,
        ]);

        return $id;
    }

    private function assertBalance(string $accountId, int $balance, int $version = 1): void
    {
        self::assertDatabaseHas('account_balances', [
            'account_id' => $accountId,
            'balance' => $balance,
            'version' => $version,
        ]);
    }
}
