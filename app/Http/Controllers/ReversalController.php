<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Idempotency\Exception\IdempotencyKeyReused;
use App\Application\Idempotency\Exception\IdempotencyRequestInProgress;
use App\Application\Reversal\Exception\ReversalCannotBeReversed;
use App\Application\Reversal\Exception\ReversalNotAllowed;
use App\Application\Reversal\Exception\TransactionAlreadyReversed;
use App\Application\Reversal\Exception\TransactionNotFound;
use App\Application\Reversal\ReverseTransaction;
use App\Domain\Ledger\Exception\InsufficientFunds;
use App\Http\ProblemDetails;
use App\Http\Requests\ReverseTransactionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReversalController
{
    public function __construct(private ReverseTransaction $reverseTransaction) {}

    public function store(ReverseTransactionRequest $request, string $transaction): JsonResponse
    {
        /** @var array{idempotency_key: string} $data */
        $data = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        try {
            $result = $this->reverseTransaction->execute($user->id, $transaction, $data['idempotency_key']);
        } catch (TransactionNotFound) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_NOT_FOUND,
                'Not Found',
                'The ledger transaction was not found.',
            );
        } catch (ReversalNotAllowed) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_FORBIDDEN,
                'Forbidden',
                'You are not allowed to reverse this transaction.',
            );
        } catch (IdempotencyRequestInProgress $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_CONFLICT,
                'Conflict',
                $exception->getMessage(),
            );
        } catch (IdempotencyKeyReused|TransactionAlreadyReversed|ReversalCannotBeReversed|InsufficientFunds $exception) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Unprocessable Content',
                $exception instanceof InsufficientFunds
                    ? 'The reversal would make a wallet balance negative.'
                    : $exception->getMessage(),
            );
        }

        return response()->json(
            $result->response->body,
            $result->response->statusCode,
            ['Idempotency-Replayed' => $result->replayed ? 'true' : 'false'],
        );
    }
}
