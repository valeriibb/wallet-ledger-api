<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Application\Ledger\PostLedgerTransaction;
use App\Application\Outbox\OutboxEvent;
use App\Application\Outbox\Port\EventPublisher;
use App\Application\Outbox\RelayOutboxEvents;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class OutboxCrashRecoveryTest extends TestCase
{
    #[Test]
    public function a_process_crash_after_business_commit_does_not_lose_the_event(): void
    {
        $resultPath = sys_get_temp_dir().'/wallet-crash-'.bin2hex(random_bytes(8)).'.txt';
        $processId = pcntl_fork();

        if ($processId === -1) {
            throw new RuntimeException('Could not fork the crash worker.');
        }

        if ($processId === 0) {
            $this->commitTransferAndCrash($resultPath);
        }

        $status = 0;
        pcntl_waitpid($processId, $status);
        DB::purge('pgsql');

        self::assertIsInt($status);
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));

        $transactionId = file_get_contents($resultPath);
        @unlink($resultPath);
        self::assertIsString($transactionId);
        self::assertDatabaseHas('ledger_transactions', ['id' => $transactionId]);

        $event = DB::table('outbox_events')
            ->where('aggregate_id', $transactionId)
            ->first();
        self::assertNotNull($event);
        self::assertNull($event->published_at);
        $eventId = $event->id;
        self::assertIsString($eventId);

        $publisher = new class implements EventPublisher
        {
            /** @var list<string> */
            public array $eventIds = [];

            public function publish(OutboxEvent $event): void
            {
                $this->eventIds[] = $event->id;
            }
        };
        $this->app->instance(EventPublisher::class, $publisher);

        $result = app(RelayOutboxEvents::class)->execute();

        self::assertGreaterThanOrEqual(1, $result->published);
        self::assertContains($eventId, $publisher->eventIds);
        self::assertDatabaseMissing('outbox_events', [
            'id' => $eventId,
            'published_at' => null,
        ]);
    }

    private function commitTransferAndCrash(string $resultPath): never
    {
        DB::purge('pgsql');
        $sourceId = $this->createWallet($this->createUser(), 100);
        $destinationId = $this->createWallet($this->createUser(), 0);
        $money = Money::ofMinor(25, Currency::fromCode('USD'));
        $transactionId = app(PostLedgerTransaction::class)->execute(
            new Posting([
                new JournalEntry($sourceId, EntryDirection::Debit, $money),
                new JournalEntry($destinationId, EntryDirection::Credit, $money),
            ]),
            LedgerTransactionType::Transfer,
            'crash-recovery-'.Str::uuid7(),
        );

        file_put_contents($resultPath, $transactionId, LOCK_EX);
        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
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
            'email' => Str::uuid7().'@crash-test.example',
        ]);
    }
}
