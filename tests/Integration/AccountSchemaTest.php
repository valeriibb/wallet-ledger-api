<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccountSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function eloquent_users_receive_uuid_version_seven_identifiers(): void
    {
        $user = User::factory()->create();
        /** @var object{version: int|string}|null $version */
        $version = DB::selectOne('SELECT uuid_extract_version(?::uuid) AS version', [$user->id]);

        self::assertNotNull($version);
        self::assertSame(7, (int) $version->version);
    }

    #[Test]
    public function a_user_can_have_only_one_wallet_per_currency(): void
    {
        $ownerId = $this->createUser();
        $this->createAccount($ownerId, 'user_wallet', 'USD');

        try {
            DB::transaction(function () use ($ownerId): void {
                $this->createAccount($ownerId, 'user_wallet', 'USD');
            });

            self::fail('The database accepted a duplicate wallet.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('accounts')->where('owner_id', $ownerId)->count());
        }
    }

    #[Test]
    public function a_user_wallet_cannot_have_a_negative_balance(): void
    {
        $accountId = $this->createAccount($this->createUser(), 'user_wallet', 'USD');

        try {
            DB::transaction(function () use ($accountId): void {
                DB::table('account_balances')->insert([
                    'account_id' => $accountId,
                    'balance' => -1,
                    'version' => 0,
                    'allow_negative' => false,
                ]);
            });

            self::fail('The database accepted a negative balance for this account.');
        } catch (QueryException) {
            self::assertDatabaseMissing('account_balances', ['account_id' => $accountId]);
        }
    }

    #[Test]
    public function a_user_wallet_cannot_enable_negative_balances(): void
    {
        $accountId = $this->createAccount($this->createUser(), 'user_wallet', 'USD');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('User wallet accounts cannot allow negative balances.');

        DB::table('account_balances')->insert([
            'account_id' => $accountId,
            'balance' => 0,
            'version' => 0,
            'allow_negative' => true,
        ]);
    }

    #[Test]
    public function a_system_account_can_have_a_negative_balance_when_allowed(): void
    {
        $accountId = $this->createAccount(null, 'system_cash', 'USD');

        DB::table('account_balances')->insert([
            'account_id' => $accountId,
            'balance' => -1,
            'version' => 0,
            'allow_negative' => true,
        ]);

        self::assertDatabaseHas('account_balances', [
            'account_id' => $accountId,
            'balance' => -1,
            'allow_negative' => true,
        ]);
    }

    private function createUser(): string
    {
        $id = (string) Str::uuid7();

        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Wallet Owner',
            'email' => $id.'@example.test',
            'password' => 'not-used-in-this-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createAccount(?string $ownerId, string $type, string $currency): string
    {
        $id = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $id,
            'owner_id' => $ownerId,
            'type' => $type,
            'currency' => $currency,
            'status' => 'active',
        ]);

        return $id;
    }
}
