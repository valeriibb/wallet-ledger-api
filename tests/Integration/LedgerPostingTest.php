<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Ledger\PostLedgerTransaction;
use App\Domain\Ledger\EntryDirection;
use App\Domain\Ledger\Exception\InsufficientFunds;
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
use Tests\TestCase;

final class LedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_posts_entries_and_updates_balances_atomically(): void
    {
        [$senderId, $recipientId] = $this->createWallets(500, 0);

        $transactionId = app(PostLedgerTransaction::class)->execute(
            $this->transfer($senderId, $recipientId, 100),
            LedgerTransactionType::Transfer,
        );

        self::assertDatabaseHas('ledger_transactions', [
            'id' => $transactionId,
            'type' => 'transfer',
            'status' => 'posted',
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $senderId,
            'direction' => 'debit',
            'amount' => 100,
            'balance_after' => 400,
        ]);
        self::assertDatabaseHas('ledger_entries', [
            'transaction_id' => $transactionId,
            'account_id' => $recipientId,
            'direction' => 'credit',
            'amount' => 100,
            'balance_after' => 100,
        ]);
        self::assertDatabaseHas('account_balances', ['account_id' => $senderId, 'balance' => 400, 'version' => 1]);
        self::assertDatabaseHas('account_balances', ['account_id' => $recipientId, 'balance' => 100, 'version' => 1]);
    }

    #[Test]
    public function insufficient_funds_roll_back_the_whole_posting(): void
    {
        [$senderId, $recipientId] = $this->createWallets(50, 0);

        try {
            app(PostLedgerTransaction::class)->execute(
                $this->transfer($senderId, $recipientId, 100),
                LedgerTransactionType::Transfer,
            );

            self::fail('The posting spent more than the sender balance.');
        } catch (InsufficientFunds) {
            self::assertSame(0, DB::table('ledger_transactions')->count());
            self::assertSame(0, DB::table('ledger_entries')->count());
            self::assertDatabaseHas('account_balances', ['account_id' => $senderId, 'balance' => 50, 'version' => 0]);
            self::assertDatabaseHas('account_balances', ['account_id' => $recipientId, 'balance' => 0, 'version' => 0]);
        }
    }

    /** @return array{string, string} */
    private function createWallets(int $senderBalance, int $recipientBalance): array
    {
        $senderOwner = User::factory()->create();
        $recipientOwner = User::factory()->create();
        $senderId = $this->createWallet((string) $senderOwner->id, $senderBalance);
        $recipientId = $this->createWallet((string) $recipientOwner->id, $recipientBalance);

        return [$senderId, $recipientId];
    }

    private function createWallet(string $ownerId, int $balance, string $currency = 'USD'): string
    {
        $accountId = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $accountId,
            'owner_id' => $ownerId,
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

        return $accountId;
    }

    private function transfer(string $senderId, string $recipientId, int $amount): Posting
    {
        $usd = Currency::fromCode('USD');

        return new Posting([
            new JournalEntry($senderId, EntryDirection::Debit, Money::ofMinor($amount, $usd)),
            new JournalEntry($recipientId, EntryDirection::Credit, Money::ofMinor($amount, $usd)),
        ]);
    }
}
