<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Idempotency\Exception\IdempotencyKeyReused;
use App\Application\Idempotency\Exception\IdempotencyRequestInProgress;
use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Idempotency\StoredResponse;
use App\Application\Transfer\Exception\TransferAccountNotFound;
use App\Application\Transfer\Exception\TransferNotAllowed;
use App\Application\Transfer\TransferFunds;
use App\Domain\Ledger\Exception\InsufficientFunds;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Exception\InvalidTransfer;
use App\Http\ProblemDetails;
use App\Http\Requests\TransferFundsRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class TransferController extends Controller
{
    public function __construct(
        private readonly TransferFunds $transferFunds,
        private readonly IdempotencyExecutor $idempotency,
    ) {}

    public function store(TransferFundsRequest $request): JsonResponse
    {
        /** @var array{idempotency_key: string, from_account_id: string, to_account_id: string, amount: int|string, currency: string, reference?: string|null} $data */
        $data = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        $source = Account::query()->findOrFail($data['from_account_id']);
        Gate::authorize('debit', $source);

        try {
            $requestHash = hash('sha256', json_encode([
                'method' => 'POST',
                'path' => '/v1/transfers',
                'from_account_id' => $data['from_account_id'],
                'to_account_id' => $data['to_account_id'],
                'amount' => (int) $data['amount'],
                'currency' => $data['currency'],
                'reference' => $data['reference'] ?? null,
            ], JSON_THROW_ON_ERROR));

            $idempotencyResult = $this->idempotency->execute(
                $user->id,
                $data['idempotency_key'],
                $requestHash,
                function () use ($user, $data): StoredResponse {
                    $result = $this->transferFunds->execute(
                        $user->id,
                        $data['from_account_id'],
                        $data['to_account_id'],
                        Money::ofMinor((int) $data['amount'], Currency::fromCode($data['currency'])),
                        $data['reference'] ?? null,
                    );

                    return new StoredResponse(
                        Response::HTTP_CREATED,
                        ['data' => [
                            'transaction_id' => $result->transactionId,
                            'from_account_id' => $result->sourceAccountId,
                            'to_account_id' => $result->destinationAccountId,
                            'amount' => $result->amount,
                            'currency' => $result->currency,
                            'status' => 'posted',
                        ]],
                        $result->transactionId,
                    );
                },
            );
        } catch (IdempotencyKeyReused $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Unprocessable Content',
                $exception->getMessage(),
            );
        } catch (IdempotencyRequestInProgress $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_CONFLICT,
                'Conflict',
                $exception->getMessage(),
            );
        } catch (TransferAccountNotFound) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_NOT_FOUND,
                'Not Found',
                'A transfer account was not found.',
            );
        } catch (TransferNotAllowed) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_FORBIDDEN,
                'Forbidden',
                'You are not allowed to debit the source account.',
            );
        } catch (InvalidTransfer $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Unprocessable Content',
                $exception->getMessage(),
            );
        } catch (InsufficientFunds) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Unprocessable Content',
                'The source account does not have enough funds.',
            );
        }

        return response()->json(
            $idempotencyResult->response->body,
            $idempotencyResult->response->statusCode,
            ['Idempotency-Replayed' => $idempotencyResult->replayed ? 'true' : 'false'],
        );
    }
}
