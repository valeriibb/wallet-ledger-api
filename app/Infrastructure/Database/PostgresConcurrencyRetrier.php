<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final class PostgresConcurrencyRetrier
{
    private const int MAX_ATTEMPTS = 3;

    private const int BASE_DELAY_MICROSECONDS = 25_000;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                if (! $this->isRetryable($exception) || $attempt === self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                $exponentialDelay = self::BASE_DELAY_MICROSECONDS * (2 ** ($attempt - 1));
                $jitter = random_int(0, 10_000);
                usleep($exponentialDelay + $jitter);
            }
        }

        throw new \LogicException('The retry loop ended without a result.');
    }

    private function isRetryable(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof QueryException
                && ! $current instanceof DeadlockException
                && ! $current instanceof PDOException) {
                continue;
            }

            $code = (string) $current->getCode();
            $message = mb_strtolower($current->getMessage());

            if ($code === '40001'
                || $code === '40P01'
                || str_contains($message, 'sqlstate[40001]')
                || str_contains($message, 'sqlstate[40p01]')
                || str_contains($message, 'deadlock detected')) {
                return true;
            }
        }

        return false;
    }
}
