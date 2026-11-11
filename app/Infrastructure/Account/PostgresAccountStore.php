<?php

declare(strict_types=1);

namespace App\Infrastructure\Account;

use App\Application\Account\Exception\AccountAlreadyExists;
use App\Application\Account\OpenedAccount;
use App\Application\Account\Port\AccountStore;
use App\Domain\Shared\ValueObject\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PostgresAccountStore implements AccountStore
{
    private const UNIQUE_WALLET_CONSTRAINT = 'accounts_user_wallet_owner_currency_unique';

    public function createUserWallet(string $ownerId, Currency $currency): OpenedAccount
    {
        try {
            return DB::transaction(function () use ($ownerId, $currency): OpenedAccount {
                $accountId = (string) Str::uuid7();

                DB::table('accounts')->insert([
                    'id' => $accountId,
                    'owner_id' => $ownerId,
                    'type' => 'user_wallet',
                    'currency' => $currency->code(),
                    'status' => 'active',
                ]);

                DB::table('account_balances')->insert([
                    'account_id' => $accountId,
                    'balance' => 0,
                    'version' => 0,
                    'allow_negative' => false,
                ]);

                return new OpenedAccount($accountId, $currency->code(), 'active', 0);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateWallet($exception)) {
                throw AccountAlreadyExists::forCurrency($currency->code());
            }

            throw $exception;
        }
    }

    private function isDuplicateWallet(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), self::UNIQUE_WALLET_CONSTRAINT);
    }
}
