<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Cash\DepositFunds;
use App\Application\Cash\Exception\CashOperationNotAllowed;
use App\Application\Cash\Exception\PaymentDeclined;
use App\Application\Cash\WithdrawFunds;
use App\Application\Idempotency\Exception\IdempotencyKeyReused;
use App\Application\Idempotency\Exception\IdempotencyRequestInProgress;
use App\Application\Idempotency\IdempotencyResult;
use App\Domain\Ledger\Exception\InsufficientFunds;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Http\ProblemDetails;
use App\Http\Requests\CashMovementRequest;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\WithdrawalRequest;
use App\Models\Account;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class CashMovementController extends Controller
{
    public function __construct(
        private readonly DepositFunds $depositFunds,
        private readonly WithdrawFunds $withdrawFunds,
    ) {}

    public function deposit(DepositRequest $request): JsonResponse
    {
        return $this->execute(
            $request,
            'view',
            fn (User $user, string $accountId, Money $money, string $key): IdempotencyResult => $this->depositFunds
                ->execute($user->id, $accountId, $money, $key),
        );
    }

    public function withdraw(WithdrawalRequest $request): JsonResponse
    {
        return $this->execute(
            $request,
            'debit',
            fn (User $user, string $accountId, Money $money, string $key): IdempotencyResult => $this->withdrawFunds
                ->execute($user->id, $accountId, $money, $key),
        );
    }

    /** @param Closure(User, string, Money, string): IdempotencyResult $operation */
    private function execute(CashMovementRequest $request, string $ability, Closure $operation): JsonResponse
    {
        /** @var array{idempotency_key: string, account_id: string, amount: int|string, currency: string} $data */
        $data = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        $account = Account::query()->findOrFail($data['account_id']);
        Gate::authorize($ability, $account);

        try {
            $result = $operation(
                $user,
                $data['account_id'],
                Money::ofMinor((int) $data['amount'], Currency::fromCode($data['currency'])),
                $data['idempotency_key'],
            );
        } catch (IdempotencyKeyReused|CashOperationNotAllowed|PaymentDeclined|InsufficientFunds $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Unprocessable Content',
                $exception instanceof InsufficientFunds
                    ? 'The wallet does not have enough funds.'
                    : $exception->getMessage(),
            );
        } catch (IdempotencyRequestInProgress $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_CONFLICT,
                'Conflict',
                $exception->getMessage(),
            );
        }

        return response()->json(
            $result->response->body,
            $result->response->statusCode,
            ['Idempotency-Replayed' => $result->replayed ? 'true' : 'false'],
        );
    }
}
