<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccountPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_can_view_and_debit_only_their_own_user_wallet(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceWallet = $this->createAccount($alice, 'user_wallet');

        self::assertTrue(Gate::forUser($alice)->allows('view', $aliceWallet));
        self::assertTrue(Gate::forUser($alice)->allows('debit', $aliceWallet));
        self::assertFalse(Gate::forUser($bob)->allows('view', $aliceWallet));
        self::assertFalse(Gate::forUser($bob)->allows('debit', $aliceWallet));
    }

    #[Test]
    public function an_admin_does_not_automatically_bypass_wallet_ownership(): void
    {
        $alice = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $aliceWallet = $this->createAccount($alice, 'user_wallet');

        self::assertFalse(Gate::forUser($admin)->allows('view', $aliceWallet));
        self::assertFalse(Gate::forUser($admin)->allows('debit', $aliceWallet));
    }

    #[Test]
    public function ownership_policy_does_not_decide_if_a_frozen_wallet_can_operate(): void
    {
        $alice = User::factory()->create();
        $frozenWallet = $this->createAccount($alice, 'user_wallet', 'frozen');

        self::assertTrue(Gate::forUser($alice)->allows('debit', $frozenWallet));
    }

    private function createAccount(User $owner, string $type, string $status = 'active'): Account
    {
        $accountId = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $accountId,
            'owner_id' => $owner->id,
            'type' => $type,
            'currency' => 'USD',
            'status' => $status,
        ]);

        return Account::query()->findOrFail($accountId);
    }
}
