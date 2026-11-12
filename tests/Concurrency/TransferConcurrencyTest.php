<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Application\Transfer\TransferFunds;
use App\Domain\Ledger\Exception\InsufficientFunds;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\ParallelProcessRunner;
use Tests\TestCase;
use Throwable;

final class TransferConcurrencyTest extends TestCase
{
    private const int WORKERS = 20;

    #[Test]
    public function one_hundred_competing_transfers_can_spend_only_the_available_balance(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $sourceId = $this->createWallet($sender, 10);
        $destinationId = $this->createWallet($recipient, 0);
        $jobs = range(1, 100);

        $results = (new ParallelProcessRunner)->run(
            $jobs,
            self::WORKERS,
            static function (int $_job) use ($sender, $sourceId, $destinationId): string {
                try {
                    app(TransferFunds::class)->execute(
                        $sender->id,
                        $sourceId,
                        $destinationId,
                        Money::ofMinor(1, Currency::fromCode('USD')),
                        null,
                    );

                    return 'posted';
                } catch (InsufficientFunds) {
                    return 'insufficient_funds';
                } catch (Throwable $exception) {
                    return 'error:'.$exception::class.':'.$exception->getMessage();
                }
            },
        );

        self::assertSame(10, $this->resultCount($results, 'posted'));
        self::assertSame(90, $this->resultCount($results, 'insufficient_funds'));
        self::assertSame([], $this->errors($results));
        self::assertSame(0, $this->balance($sourceId));
        self::assertSame(10, $this->balance($destinationId));
        self::assertSame(10, $this->debitEntryCount($sourceId));
        self::assertSame(10, $this->balance($sourceId) + $this->balance($destinationId));
    }

    #[Test]
    public function five_hundred_opposite_transfers_preserve_the_total_without_failures(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceAccountId = $this->createWallet($alice, 1000);
        $bobAccountId = $this->createWallet($bob, 1000);
        $jobs = range(0, 499);

        $results = (new ParallelProcessRunner)->run(
            $jobs,
            self::WORKERS,
            static function (int $job) use ($alice, $bob, $aliceAccountId, $bobAccountId): string {
                $leftToRight = $job % 2 === 0;

                try {
                    app(TransferFunds::class)->execute(
                        $leftToRight ? $alice->id : $bob->id,
                        $leftToRight ? $aliceAccountId : $bobAccountId,
                        $leftToRight ? $bobAccountId : $aliceAccountId,
                        Money::ofMinor(1, Currency::fromCode('USD')),
                        null,
                    );

                    return 'posted';
                } catch (Throwable $exception) {
                    return 'error:'.$exception::class.':'.$exception->getMessage();
                }
            },
        );

        self::assertSame(500, $this->resultCount($results, 'posted'));
        self::assertSame([], $this->errors($results));
        self::assertSame(1000, $this->balance($aliceAccountId));
        self::assertSame(1000, $this->balance($bobAccountId));
        self::assertSame(2000, $this->balance($aliceAccountId) + $this->balance($bobAccountId));
        self::assertSame(500, $this->entryCount([$aliceAccountId, $bobAccountId]) / 2);
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

    /** @param list<string> $results */
    private function resultCount(array $results, string $expected): int
    {
        return count(array_filter($results, static fn (string $result): bool => $result === $expected));
    }

    /**
     * @param  list<string>  $results
     * @return list<string>
     */
    private function errors(array $results): array
    {
        return array_values(array_filter(
            $results,
            static fn (string $result): bool => str_starts_with($result, 'error:')
                || str_starts_with($result, 'worker_error:'),
        ));
    }

    private function balance(string $accountId): int
    {
        $value = DB::table('account_balances')
            ->where('account_id', $accountId)
            ->value('balance');

        $balance = filter_var($value, FILTER_VALIDATE_INT);

        if ($balance === false) {
            throw new RuntimeException('PostgreSQL returned an invalid account balance.');
        }

        return $balance;
    }

    private function debitEntryCount(string $accountId): int
    {
        return DB::table('ledger_entries')
            ->where('account_id', $accountId)
            ->where('direction', 'debit')
            ->count();
    }

    /** @param list<string> $accountIds */
    private function entryCount(array $accountIds): int
    {
        return DB::table('ledger_entries')->whereIn('account_id', $accountIds)->count();
    }
}
