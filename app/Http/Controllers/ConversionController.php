<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Conversion\ConvertCurrency;
use App\Application\Conversion\Exception\ConversionAccountNotFound;
use App\Application\Conversion\Exception\ConversionNotAllowed;
use App\Application\Currency\Exception\ExchangeRateUnavailable;
use App\Application\Currency\Port\ExchangeRateProvider;
use App\Application\Idempotency\Exception\IdempotencyKeyReused;
use App\Application\Idempotency\Exception\IdempotencyRequestInProgress;
use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Idempotency\StoredResponse;
use App\Domain\Conversion\Exception\InvalidConversion;
use App\Domain\Ledger\Exception\InsufficientFunds;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Http\ProblemDetails;
use App\Http\Requests\ConvertCurrencyRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class ConversionController extends Controller
{
    public function __construct(
        private readonly ExchangeRateProvider $rates,
        private readonly ConvertCurrency $convertCurrency,
        private readonly IdempotencyExecutor $idempotency,
    ) {}

    public function store(ConvertCurrencyRequest $request): JsonResponse
    {
        /** @var array{idempotency_key: string, from_account_id: string, to_account_id: string, amount: int|string, from_currency: string, to_currency: string, reference?: string|null} $data */
        $data = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        $source = Account::query()->findOrFail($data['from_account_id']);
        Gate::authorize('debit', $source);

        try {
            $inputCurrency = Currency::fromCode($data['from_currency']);
            $outputCurrency = Currency::fromCode($data['to_currency']);
            $rate = $this->rates->quote($inputCurrency, $outputCurrency);
            $requestHash = hash('sha256', json_encode([
                'method' => 'POST',
                'path' => '/v1/conversions',
                'from_account_id' => $data['from_account_id'],
                'to_account_id' => $data['to_account_id'],
                'amount' => (int) $data['amount'],
                'from_currency' => $data['from_currency'],
                'to_currency' => $data['to_currency'],
                'reference' => $data['reference'] ?? null,
            ], JSON_THROW_ON_ERROR));

            $idempotencyResult = $this->idempotency->execute(
                $user->id,
                $data['idempotency_key'],
                $requestHash,
                function () use ($user, $data, $inputCurrency, $rate): StoredResponse {
                    $result = $this->convertCurrency->execute(
                        $user->id,
                        $data['from_account_id'],
                        $data['to_account_id'],
                        Money::ofMinor((int) $data['amount'], $inputCurrency),
                        $rate,
                        $data['reference'] ?? null,
                    );

                    return new StoredResponse(
                        Response::HTTP_CREATED,
                        ['data' => [
                            'transaction_id' => $result->transactionId,
                            'from_account_id' => $result->sourceAccountId,
                            'to_account_id' => $result->destinationAccountId,
                            'input_amount' => $result->inputAmount,
                            'input_currency' => $result->inputCurrency,
                            'output_amount' => $result->outputAmount,
                            'output_currency' => $result->outputCurrency,
                            'exchange_rate' => $result->rate,
                            'status' => 'posted',
                        ]],
                        $result->transactionId,
                    );
                },
            );
        } catch (IdempotencyKeyReused $exception) {
            return $this->problem($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'Unprocessable Content', $exception->getMessage());
        } catch (IdempotencyRequestInProgress $exception) {
            return $this->problem($request, Response::HTTP_CONFLICT, 'Conflict', $exception->getMessage());
        } catch (ConversionAccountNotFound) {
            return $this->problem($request, Response::HTTP_NOT_FOUND, 'Not Found', 'A conversion account was not found.');
        } catch (ConversionNotAllowed) {
            return $this->problem($request, Response::HTTP_FORBIDDEN, 'Forbidden', 'Both wallets must belong to the authenticated user.');
        } catch (ExchangeRateUnavailable|InvalidConversion $exception) {
            return $this->problem($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'Unprocessable Content', $exception->getMessage());
        } catch (InsufficientFunds) {
            return $this->problem($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'Unprocessable Content', 'The source account does not have enough funds.');
        }

        return response()->json(
            $idempotencyResult->response->body,
            $idempotencyResult->response->statusCode,
            ['Idempotency-Replayed' => $idempotencyResult->replayed ? 'true' : 'false'],
        );
    }

    private function problem(
        ConvertCurrencyRequest $request,
        int $status,
        string $title,
        string $detail,
    ): JsonResponse {
        return ProblemDetails::response($request, $status, $title, $detail);
    }
}
