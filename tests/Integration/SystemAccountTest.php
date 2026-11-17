<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Account\GetSystemAccount;
use App\Domain\Account\SystemAccountType;
use App\Domain\Shared\ValueObject\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SystemAccountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_one_system_account_for_each_type_and_currency(): void
    {
        $service = app(GetSystemAccount::class);
        $usd = Currency::fromCode('USD');

        $first = $service->execute(SystemAccountType::Cash, $usd);
        $second = $service->execute(SystemAccountType::Cash, $usd);

        self::assertSame($first->id, $second->id);
        self::assertSame(SystemAccountType::Cash, $first->type);
        self::assertSame('USD', $first->currency);
        self::assertDatabaseCount('accounts', 1);
        self::assertDatabaseHas('accounts', [
            'id' => $first->id,
            'owner_id' => null,
            'type' => 'system_cash',
            'currency' => 'USD',
            'status' => 'active',
        ]);
        self::assertDatabaseHas('account_balances', [
            'account_id' => $first->id,
            'balance' => 0,
            'version' => 0,
            'allow_negative' => true,
        ]);
    }

    #[Test]
    public function different_types_and_currencies_use_different_accounts(): void
    {
        $service = app(GetSystemAccount::class);

        $usdCash = $service->execute(SystemAccountType::Cash, Currency::fromCode('USD'));
        $eurCash = $service->execute(SystemAccountType::Cash, Currency::fromCode('EUR'));
        $usdFees = $service->execute(SystemAccountType::Fees, Currency::fromCode('USD'));

        self::assertNotSame($usdCash->id, $eurCash->id);
        self::assertNotSame($usdCash->id, $usdFees->id);
        self::assertDatabaseCount('accounts', 3);
        self::assertDatabaseCount('account_balances', 3);
    }
}
