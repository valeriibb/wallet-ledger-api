<?php

declare(strict_types=1);

namespace App\Infrastructure\Idempotency;

use App\Application\Idempotency\Exception\IdempotencyKeyReused;
use App\Application\Idempotency\Exception\IdempotencyRequestInProgress;
use App\Application\Idempotency\IdempotencyResult;
use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Idempotency\StoredResponse;
use App\Infrastructure\Database\PostgresConcurrencyRetrier;
use Closure;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class PostgresIdempotencyExecutor implements IdempotencyExecutor
{
    public function __construct(private PostgresConcurrencyRetrier $retrier) {}

    /** @param Closure(): StoredResponse $operation */
    public function execute(
        string $ownerId,
        string $key,
        string $requestHash,
        Closure $operation,
    ): IdempotencyResult {
        return $this->retrier->run(fn (): IdempotencyResult => DB::transaction(
            fn (): IdempotencyResult => $this->executeInTransaction(
                $ownerId,
                $key,
                $requestHash,
                $operation,
            ),
        ));
    }

    /** @param Closure(): StoredResponse $operation */
    private function executeInTransaction(
        string $ownerId,
        string $key,
        string $requestHash,
        Closure $operation,
    ): IdempotencyResult {
        $now = now();
        $inserted = DB::table('idempotency_keys')->insertOrIgnore([
            'owner_id' => $ownerId,
            'key' => $key,
            'request_hash' => $requestHash,
            'status' => 'processing',
            'locked_until' => $now->copy()->addSeconds(30),
            'created_at' => $now,
            'expires_at' => $now->copy()->addDay(),
        ]);

        if ($inserted === 0) {
            $stored = DB::table('idempotency_keys')
                ->where('owner_id', $ownerId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (! is_object($stored)) {
                throw new RuntimeException('The idempotency key disappeared during processing.');
            }

            /** @var array<string, mixed> $data */
            $data = (array) $stored;

            $expired = DB::table('idempotency_keys')
                ->where('owner_id', $ownerId)
                ->where('key', $key)
                ->where('expires_at', '<=', $now)
                ->update([
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'response_code' => null,
                    'response_body' => null,
                    'transaction_id' => null,
                    'locked_until' => $now->copy()->addSeconds(30),
                    'created_at' => $now,
                    'expires_at' => $now->copy()->addDay(),
                ]);

            if ($expired === 0) {
                if (($data['request_hash'] ?? null) !== $requestHash) {
                    throw new IdempotencyKeyReused;
                }

                if (($data['status'] ?? null) === 'completed') {
                    return new IdempotencyResult($this->storedResponse($data), true);
                }

                $takenOver = DB::table('idempotency_keys')
                    ->where('owner_id', $ownerId)
                    ->where('key', $key)
                    ->where('status', 'processing')
                    ->where('locked_until', '<=', $now)
                    ->update(['locked_until' => $now->copy()->addSeconds(30)]);

                if ($takenOver !== 1) {
                    throw new IdempotencyRequestInProgress;
                }
            }
        }

        $response = $operation();

        DB::table('idempotency_keys')
            ->where('owner_id', $ownerId)
            ->where('key', $key)
            ->update([
                'status' => 'completed',
                'response_code' => $response->statusCode,
                'response_body' => json_encode($response->body, JSON_THROW_ON_ERROR),
                'transaction_id' => $response->transactionId,
            ]);

        return new IdempotencyResult($response, false);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    private function storedResponse(array $data): StoredResponse
    {
        $statusCode = filter_var($data['response_code'] ?? null, FILTER_VALIDATE_INT);
        $bodyJson = $data['response_body'] ?? null;
        $transactionId = $data['transaction_id'] ?? null;

        if ($statusCode === false || ! is_string($bodyJson) || ! is_string($transactionId)) {
            throw new RuntimeException('The stored idempotency response is invalid.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($bodyJson, true, flags: JSON_THROW_ON_ERROR);

        return new StoredResponse($statusCode, $body, $transactionId);
    }
}
