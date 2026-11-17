<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Cash\DepositFunds;
use App\Application\Cash\WithdrawFunds;
use App\Application\Fee\ChargeFee;
use App\Application\Reversal\Port\ReversalStore;
use App\Application\Transfer\TransferFunds;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class LedgerSystemBalancePropertyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('operationCases')]
    public function mixed_operations_preserve_zero_sum_in_every_currency(
        int $aliceDeposit,
        int $bobDeposit,
        int $transferAmount,
        int $feeAmount,
        int $withdrawalAmount,
    ): void {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceUsd = $this->createWallet($alice, 'USD');
        $bobUsd = $this->createWallet($bob, 'USD');
        $aliceEur = $this->createWallet($alice, 'EUR');
        $usd = Currency::fromCode('USD');
        $eur = Currency::fromCode('EUR');

        app(DepositFunds::class)->execute($alice->id, $aliceUsd, Money::ofMinor($aliceDeposit, $usd), 'alice-usd-deposit');
        app(DepositFunds::class)->execute($bob->id, $bobUsd, Money::ofMinor($bobDeposit, $usd), 'bob-usd-deposit');
        app(DepositFunds::class)->execute($alice->id, $aliceEur, Money::ofMinor(800, $eur), 'alice-eur-deposit');
        $transfer = app(TransferFunds::class)->execute(
            $alice->id,
            $aliceUsd,
            $bobUsd,
            Money::ofMinor($transferAmount, $usd),
            null,
        );
        app(ChargeFee::class)->execute($alice->id, $aliceUsd, Money::ofMinor($feeAmount, $usd), 'alice-usd-fee');
        app(ChargeFee::class)->execute($alice->id, $aliceEur, Money::ofMinor(30, $eur), 'alice-eur-fee');
        app(WithdrawFunds::class)->execute($bob->id, $bobUsd, Money::ofMinor($withdrawalAmount, $usd), 'bob-usd-withdrawal');
        app(ReversalStore::class)->reverse($alice->id, $transfer->transactionId);

        $entryTotals = DB::table('ledger_entries')
            ->select('currency')
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS total")
            ->groupBy('currency')
            ->pluck('total', 'currency');
        $balanceTotals = DB::table('account_balances')
            ->join('accounts', 'accounts.id', '=', 'account_balances.account_id')
            ->select('accounts.currency')
            ->selectRaw('SUM(account_balances.balance) AS total')
            ->groupBy('accounts.currency')
            ->pluck('total', 'accounts.currency');

        self::assertSame(0, $this->integerTotal($entryTotals->get('USD')));
        self::assertSame(0, $this->integerTotal($entryTotals->get('EUR')));
        self::assertSame(0, $this->integerTotal($balanceTotals->get('USD')));
        self::assertSame(0, $this->integerTotal($balanceTotals->get('EUR')));
    }

    /** @return iterable<string, array{int, int, int, int, int}> */
    public static function operationCases(): iterable
    {
        yield 'small values' => [10, 5, 3, 1, 2];
        yield 'round values' => [1000, 500, 200, 25, 100];
        yield 'single minor units' => [3, 2, 1, 1, 1];
        yield 'uneven values' => [997, 431, 173, 29, 211];
        yield 'large values' => [1_000_000, 800_000, 300_000, 12_345, 250_000];
    }

    private function createWallet(User $owner, string $currency): string
    {
        $accountId = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $accountId,
            'owner_id' => $owner->id,
            'type' => 'user_wallet',
            'currency' => $currency,
            'status' => 'active',
        ]);
        DB::table('account_balances')->insert([
            'account_id' => $accountId,
            'balance' => 0,
            'version' => 0,
            'allow_negative' => false,
        ]);

        return $accountId;
    }

    private function integerTotal(mixed $value): int
    {
        $total = filter_var($value, FILTER_VALIDATE_INT);

        if ($total === false) {
            throw new RuntimeException('PostgreSQL returned an invalid balance total.');
        }

        return $total;
    }
}
