<?php

declare(strict_types=1);

namespace App\Infrastructure\Account;

use App\Application\Account\AccountBalance;
use App\Application\Account\AccountSummary;
use App\Application\Account\Port\AccountReader;
use App\Application\Account\StatementEntry;
use App\Application\Account\StatementPage;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresAccountReader implements AccountReader
{
    public function listUserWallets(string $ownerId): array
    {
        /** @var list<object{id: string, type: string, currency: string, status: string}> $rows */
        $rows = DB::table('accounts')
            ->select(['id', 'type', 'currency', 'status'])
            ->where('owner_id', $ownerId)
            ->where('type', 'user_wallet')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_map(
            static fn (object $row): AccountSummary => new AccountSummary(
                $row->id,
                $row->type,
                $row->currency,
                $row->status,
            ),
            $rows,
        );
    }

    public function balance(string $accountId): AccountBalance
    {
        /** @var object{account_id: string, currency: string, balance: int|string}|null $row */
        $row = DB::table('accounts')
            ->join('account_balances', 'account_balances.account_id', '=', 'accounts.id')
            ->select(['accounts.id as account_id', 'accounts.currency', 'account_balances.balance'])
            ->where('accounts.id', $accountId)
            ->first();

        if ($row === null || filter_var($row->balance, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('The account balance row is missing or invalid.');
        }

        return new AccountBalance($row->account_id, $row->currency, (int) $row->balance);
    }

    public function statement(string $accountId, int $limit, ?string $cursor): StatementPage
    {
        $decodedCursor = Cursor::fromEncoded($cursor);

        /** @var CursorPaginator<int, object{id: string, transaction_id: string, direction: string, amount: int|string, currency: string, balance_after: int|string, created_at: string}> $page */
        $page = DB::table('ledger_entries')
            ->select([
                'id',
                'transaction_id',
                'direction',
                'amount',
                'currency',
                'balance_after',
                'created_at',
            ])
            ->where('account_id', $accountId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, cursor: $decodedCursor);

        $entries = [];

        foreach ($page->items() as $row) {
            $amount = filter_var($row->amount, FILTER_VALIDATE_INT);
            $balanceAfter = filter_var($row->balance_after, FILTER_VALIDATE_INT);

            if ($amount === false || $balanceAfter === false) {
                throw new RuntimeException('The database returned an invalid ledger entry.');
            }

            $entries[] = new StatementEntry(
                $row->id,
                $row->transaction_id,
                $row->direction,
                $amount,
                $row->currency,
                $balanceAfter,
                $row->created_at,
            );
        }

        return new StatementPage($entries, $page->nextCursor()?->encode());
    }
}
