<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Fee\ChargeFee;
use App\Application\Fee\Exception\FeeNotAllowed;
use App\Domain\Ledger\Exception\InsufficientFunds;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FeeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_charges_a_balanced_fee_into_the_system_fees_account(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 1000);

        $result = app(ChargeFee::class)->execute(
            $owner->id,
            $walletId,
            Money::ofMinor(75, Currency::fromCode('USD')),
            'fee-1',
            'transfer-commission',
        );

        $transactionId = $result->response->transactionId;
        self::assertIsString($transactionId);
        $systemFeesId = DB::table('accounts')->where('type', 'system_fees')->value('id');
        self::assertIsString($systemFeesId);
        self::assertDatabaseHas('ledger_transactions', [
            'id' => $transactionId,
            'type' => 'fee',
            'reference' => 'transfer-commission',
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $walletId,
            'direction' => 'debit',
            'amount' => 75,
            'balance_after' => 925,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $systemFeesId,
            'direction' => 'credit',
            'amount' => 75,
            'balance_after' => 75,
        ]);
    }

    #[Test]
    public function replaying_a_fee_does_not_charge_the_wallet_twice(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 1000);
        $money = Money::ofMinor(75, Currency::fromCode('USD'));
        $service = app(ChargeFee::class);

        $first = $service->execute($owner->id, $walletId, $money, 'same-fee');
        $second = $service->execute($owner->id, $walletId, $money, 'same-fee');

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->response->transactionId, $second->response->transactionId);
        self::assertDatabaseCount('ledger_transactions', 1);
        self::assertDatabaseHas('account_balances', ['account_id' => $walletId, 'balance' => 925, 'version' => 1]);
    }

    #[Test]
    public function a_fee_cannot_make_a_user_wallet_negative(): void
    {
        $owner = User::factory()->create();
        $walletId = $this->createWallet($owner, 50);

        $this->expectException(InsufficientFunds::class);

        app(ChargeFee::class)->execute(
            $owner->id,
            $walletId,
            Money::ofMinor(75, Currency::fromCode('USD')),
            'too-large-fee',
        );
    }

    #[Test]
    public function a_user_cannot_charge_a_fee_to_another_users_wallet(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $walletId = $this->createWallet($owner, 1000);

        $this->expectException(FeeNotAllowed::class);
        $this->expectExceptionMessage('The wallet does not belong to the fee owner.');

        app(ChargeFee::class)->execute(
            $attacker->id,
            $walletId,
            Money::ofMinor(75, Currency::fromCode('USD')),
            'foreign-fee',
        );
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
}
