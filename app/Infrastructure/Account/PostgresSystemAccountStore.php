<?php

declare(strict_types=1);

namespace App\Infrastructure\Account;

use App\Application\Account\Port\SystemAccountStore;
use App\Application\Account\SystemAccount;
use App\Domain\Account\SystemAccountType;
use App\Domain\Shared\ValueObject\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PostgresSystemAccountStore implements SystemAccountStore
{
    public function getOrCreate(SystemAccountType $type, Currency $currency): SystemAccount
    {
        return DB::transaction(function () use ($type, $currency): SystemAccount {
            $accountId = (string) Str::uuid7();
            $inserted = DB::table('accounts')->insertOrIgnore([
                'id' => $accountId,
                'owner_id' => null,
                'type' => $type->value,
                'currency' => $currency->code(),
                'status' => 'active',
            ]);

            if ($inserted === 1) {
                DB::table('account_balances')->insert([
                    'account_id' => $accountId,
                    'balance' => 0,
                    'version' => 0,
                    'allow_negative' => true,
                ]);
            }

            $storedId = DB::table('accounts')
                ->where('type', $type->value)
                ->where('currency', $currency->code())
                ->value('id');

            if (! is_string($storedId)) {
                throw new RuntimeException('The system account could not be loaded.');
            }

            return new SystemAccount($storedId, $type, $currency->code());
        });
    }
}
