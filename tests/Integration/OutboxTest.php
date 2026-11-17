<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Ledger\PostLedgerTransaction;
use App\Application\Outbox\OutboxEvent;
use App\Application\Outbox\Port\EventPublisher;
use App\Application\Outbox\Port\OutboxWriter;
use App\Application\Outbox\RelayOutboxEvents;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\LedgerTransactionType;
use App\Domain\Ledger\Posting;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class OutboxTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function posting_a_ledger_transaction_creates_a_pending_outbox_event(): void
    {
        [$sourceId, $destinationId] = $this->createWallets();

        $transactionId = app(PostLedgerTransaction::class)->execute(
            $this->posting($sourceId, $destinationId),
            LedgerTransactionType::Transfer,
            'invoice-1',
        );

        $event = DB::table('outbox_events')->first();
        self::assertNotNull($event);
        self::assertSame('ledger_transaction', $event->aggregate_type);
        self::assertSame($transactionId, $event->aggregate_id);
        self::assertSame('transfer.completed', $event->event_type);
        self::assertNull($event->published_at);
        $attempts = filter_var($event->attempts, FILTER_VALIDATE_INT);
        self::assertNotFalse($attempts);
        self::assertSame(0, $attempts);
        self::assertIsString($event->payload);
        self::assertJsonStringEqualsJsonString(json_encode([
            'transaction_id' => $transactionId,
            'type' => 'transfer',
            'reference' => 'invoice-1',
            'reversal_of' => null,
        ], JSON_THROW_ON_ERROR), $event->payload);
    }

    #[Test]
    public function an_outbox_write_failure_rolls_back_the_ledger_and_balances(): void
    {
        [$sourceId, $destinationId] = $this->createWallets();
        $this->app->instance(OutboxWriter::class, new class implements OutboxWriter
        {
            public function append(string $aggregateType, string $aggregateId, string $eventType, array $payload): string
            {
                throw new RuntimeException('Outbox unavailable.');
            }
        });

        try {
            app(PostLedgerTransaction::class)->execute(
                $this->posting($sourceId, $destinationId),
                LedgerTransactionType::Transfer,
            );
            self::fail('The posting should fail when its outbox event cannot be stored.');
        } catch (RuntimeException $exception) {
            self::assertSame('Outbox unavailable.', $exception->getMessage());
        }

        self::assertDatabaseCount('ledger_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        self::assertDatabaseHas('account_balances', ['account_id' => $sourceId, 'balance' => 500, 'version' => 0]);
        self::assertDatabaseHas('account_balances', ['account_id' => $destinationId, 'balance' => 0, 'version' => 0]);
    }

    #[Test]
    public function the_relay_publishes_and_marks_a_batch(): void
    {
        [$sourceId, $destinationId] = $this->createWallets();
        app(PostLedgerTransaction::class)->execute(
            $this->posting($sourceId, $destinationId),
            LedgerTransactionType::Transfer,
        );
        $publisher = new class implements EventPublisher
        {
            /** @var list<OutboxEvent> */
            public array $events = [];

            public function publish(OutboxEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $this->app->instance(EventPublisher::class, $publisher);

        $result = app(RelayOutboxEvents::class)->execute(10);

        self::assertSame(1, $result->processed);
        self::assertSame(1, $result->published);
        self::assertSame(0, $result->failed);
        self::assertCount(1, $publisher->events);
        self::assertDatabaseMissing('outbox_events', ['published_at' => null]);
        self::assertDatabaseHas('outbox_events', ['attempts' => 1]);
    }

    #[Test]
    public function a_failed_publish_remains_pending_and_can_be_retried(): void
    {
        [$sourceId, $destinationId] = $this->createWallets();
        app(PostLedgerTransaction::class)->execute(
            $this->posting($sourceId, $destinationId),
            LedgerTransactionType::Transfer,
        );
        $this->app->instance(EventPublisher::class, new class implements EventPublisher
        {
            public function publish(OutboxEvent $event): void
            {
                throw new RuntimeException('Broker unavailable.');
            }
        });

        $failed = app(RelayOutboxEvents::class)->execute();
        self::assertSame(1, $failed->failed);
        self::assertDatabaseHas('outbox_events', ['published_at' => null, 'attempts' => 1]);

        $this->app->instance(EventPublisher::class, new class implements EventPublisher
        {
            public function publish(OutboxEvent $event): void {}
        });
        $retried = app(RelayOutboxEvents::class)->execute();

        self::assertSame(1, $retried->published);
        self::assertDatabaseHas('outbox_events', ['attempts' => 2]);
        self::assertDatabaseMissing('outbox_events', ['published_at' => null]);
    }

    /** @return array{string, string} */
    private function createWallets(): array
    {
        $sourceId = $this->createWallet(User::factory()->create(), 500);
        $destinationId = $this->createWallet(User::factory()->create(), 0);

        return [$sourceId, $destinationId];
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

    private function posting(string $sourceId, string $destinationId): Posting
    {
        $money = Money::ofMinor(100, Currency::fromCode('USD'));

        return new Posting([
            new JournalEntry($sourceId, EntryDirection::Debit, $money),
            new JournalEntry($destinationId, EntryDirection::Credit, $money),
        ]);
    }
}
