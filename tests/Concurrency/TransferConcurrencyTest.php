<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Idempotency\StoredResponse;
use App\Application\Reversal\Exception\TransactionAlreadyReversed;
use App\Application\Reversal\Port\ReversalStore;
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
        $sender = $this->createUser();
        $recipient = $this->createUser();
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
        $alice = $this->createUser();
        $bob = $this->createUser();
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

    #[Test]
    public function twenty_requests_with_one_idempotency_key_create_one_transfer(): void
    {
        $sender = $this->createUser();
        $recipient = $this->createUser();
        $sourceId = $this->createWallet($sender, 1000);
        $destinationId = $this->createWallet($recipient, 0);
        $key = (string) Str::uuid();
        $requestHash = hash('sha256', 'same-transfer-request');
        $jobs = range(1, 20);

        $results = (new ParallelProcessRunner)->run(
            $jobs,
            self::WORKERS,
            static function (int $_job) use (
                $sender,
                $sourceId,
                $destinationId,
                $key,
                $requestHash,
            ): string {
                try {
                    $result = app(IdempotencyExecutor::class)->execute(
                        $sender->id,
                        $key,
                        $requestHash,
                        static function () use ($sender, $sourceId, $destinationId): StoredResponse {
                            $transfer = app(TransferFunds::class)->execute(
                                $sender->id,
                                $sourceId,
                                $destinationId,
                                Money::ofMinor(100, Currency::fromCode('USD')),
                                null,
                            );

                            return new StoredResponse(
                                201,
                                ['transaction_id' => $transfer->transactionId],
                                $transfer->transactionId,
                            );
                        },
                    );

                    return $result->replayed ? 'replayed' : 'created';
                } catch (Throwable $exception) {
                    return 'error:'.$exception::class.':'.$exception->getMessage();
                }
            },
        );

        self::assertSame(1, $this->resultCount($results, 'created'));
        self::assertSame(19, $this->resultCount($results, 'replayed'));
        self::assertSame([], $this->errors($results));
        self::assertSame(900, $this->balance($sourceId));
        self::assertSame(100, $this->balance($destinationId));
        self::assertSame(2, $this->entryCount([$sourceId, $destinationId]));
        self::assertSame(1, DB::table('idempotency_keys')
            ->where('owner_id', $sender->id)
            ->where('key', $key)
            ->where('status', 'completed')
            ->count());
    }

    #[Test]
    public function competing_reversals_can_create_only_one_reversal_transaction(): void
    {
        $sender = $this->createUser();
        $recipient = $this->createUser();
        $sourceId = $this->createWallet($sender, 500);
        $destinationId = $this->createWallet($recipient, 0);
        $original = app(TransferFunds::class)->execute(
            $sender->id,
            $sourceId,
            $destinationId,
            Money::ofMinor(100, Currency::fromCode('USD')),
            null,
        );

        $results = (new ParallelProcessRunner)->run(
            range(1, 20),
            self::WORKERS,
            static function (int $_job) use ($sender, $original): string {
                try {
                    app(ReversalStore::class)->reverse($sender->id, $original->transactionId);

                    return 'created';
                } catch (TransactionAlreadyReversed) {
                    return 'already_reversed';
                } catch (Throwable $exception) {
                    return 'error:'.$exception::class.':'.$exception->getMessage();
                }
            },
        );

        self::assertSame(1, $this->resultCount($results, 'created'));
        self::assertSame(19, $this->resultCount($results, 'already_reversed'));
        self::assertSame([], $this->errors($results));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'reversal')->count());
        self::assertSame(500, $this->balance($sourceId));
        self::assertSame(0, $this->balance($destinationId));
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

    private function createUser(): User
    {
        return User::factory()->create([
            'email' => Str::uuid7().'@concurrency-test.example',
        ]);
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
