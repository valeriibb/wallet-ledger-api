<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccountOpeningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_authenticated_user_can_open_a_wallet(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/v1/accounts', ['currency' => 'USD']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'user_wallet')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.balance', 0);

        $accountId = $response->json('data.id');
        self::assertIsString($accountId);

        self::assertDatabaseHas('accounts', [
            'id' => $accountId,
            'owner_id' => $user->id,
            'type' => 'user_wallet',
            'currency' => 'USD',
            'status' => 'active',
        ]);
        self::assertDatabaseHas('account_balances', [
            'account_id' => $accountId,
            'balance' => 0,
            'version' => 0,
            'allow_negative' => false,
        ]);
    }

    #[Test]
    public function duplicate_wallet_currency_returns_a_conflict(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/v1/accounts', ['currency' => 'USD'])->assertCreated();
        $this->postJson('/v1/accounts', ['currency' => 'USD'])
            ->assertConflict()
            ->assertJsonPath('detail', 'A wallet for USD already exists.');

        self::assertSame(1, $user->accounts()->where('currency', 'USD')->count());
    }

    #[Test]
    public function only_a_normal_user_role_can_open_a_wallet(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/v1/accounts', ['currency' => 'USD'])->assertForbidden();
        self::assertDatabaseCount('accounts', 0);
    }

    #[Test]
    public function wallet_currency_must_be_an_uppercase_iso_style_code(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/v1/accounts', ['currency' => 'usd'])
            ->assertUnprocessable()
            ->assertJsonPath('title', 'Unprocessable Content');

        self::assertDatabaseCount('accounts', 0);
    }

    #[Test]
    public function authentication_is_required_to_open_a_wallet(): void
    {
        $this->postJson('/v1/accounts', ['currency' => 'USD'])->assertUnauthorized();
        self::assertDatabaseCount('accounts', 0);
    }
}
