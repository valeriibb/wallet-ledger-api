<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Account\AccountSummary;
use App\Application\Account\Exception\AccountAlreadyExists;
use App\Application\Account\GetAccountBalance;
use App\Application\Account\GetAccountStatement;
use App\Application\Account\ListAccounts;
use App\Application\Account\OpenAccount;
use App\Application\Account\StatementEntry;
use App\Domain\Shared\ValueObject\Currency;
use App\Http\ProblemDetails;
use App\Http\Requests\AccountStatementRequest;
use App\Http\Requests\OpenAccountRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class AccountController extends Controller
{
    public function __construct(
        private readonly OpenAccount $openAccount,
        private readonly ListAccounts $listAccounts,
        private readonly GetAccountBalance $getAccountBalance,
        private readonly GetAccountStatement $getAccountStatement,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        Gate::authorize('viewAny', Account::class);

        $accounts = $this->listAccounts->execute($user->id);

        return response()->json([
            'data' => array_map(static fn (AccountSummary $account): array => [
                'id' => $account->id,
                'type' => $account->type,
                'currency' => $account->currency,
                'status' => $account->status,
            ], $accounts),
        ]);
    }

    public function store(OpenAccountRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        Gate::authorize('create', Account::class);

        /** @var array{currency: string} $data */
        $data = $request->validated();

        try {
            $account = $this->openAccount->execute(
                $user->id,
                Currency::fromCode($data['currency']),
            );
        } catch (AccountAlreadyExists $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_CONFLICT,
                'Conflict',
                $exception->getMessage(),
            );
        }

        return response()->json([
            'data' => [
                'id' => $account->id,
                'type' => 'user_wallet',
                'currency' => $account->currency,
                'status' => $account->status,
                'balance' => $account->balance,
            ],
        ], Response::HTTP_CREATED);
    }

    public function balance(Request $request, Account $account): JsonResponse
    {
        $this->authenticatedUser($request);
        Gate::authorize('view', $account);

        $balance = $this->getAccountBalance->execute($account->id);

        return response()->json([
            'data' => [
                'account_id' => $balance->accountId,
                'currency' => $balance->currency,
                'balance' => $balance->balance,
            ],
        ]);
    }

    public function entries(AccountStatementRequest $request, Account $account): JsonResponse
    {
        $this->authenticatedUser($request);
        Gate::authorize('view', $account);

        /** @var array{limit?: int|string, cursor?: string|null} $data */
        $data = $request->validated();
        $limit = isset($data['limit']) ? (int) $data['limit'] : 20;
        $page = $this->getAccountStatement->execute(
            $account->id,
            $limit,
            $data['cursor'] ?? null,
        );

        return response()->json([
            'data' => array_map(static fn (StatementEntry $entry): array => [
                'id' => $entry->id,
                'transaction_id' => $entry->transactionId,
                'direction' => $entry->direction,
                'amount' => $entry->amount,
                'currency' => $entry->currency,
                'balance_after' => $entry->balanceAfter,
                'created_at' => $entry->createdAt,
            ], $page->entries),
            'meta' => [
                'next_cursor' => $page->nextCursor,
            ],
        ]);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return $user;
    }
}
