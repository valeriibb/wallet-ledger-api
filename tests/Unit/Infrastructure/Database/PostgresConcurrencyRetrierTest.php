<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database;

use App\Infrastructure\Database\PostgresConcurrencyRetrier;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostgresConcurrencyRetrierTest extends TestCase
{
    #[Test]
    public function it_retries_a_postgres_deadlock_and_returns_the_later_result(): void
    {
        $attempts = 0;

        $result = (new PostgresConcurrencyRetrier)->run(
            static function () use (&$attempts): string {
                $attempts++;

                if ($attempts < 3) {
                    throw new PDOException('SQLSTATE[40P01]: Deadlock detected');
                }

                return 'committed';
            },
        );

        self::assertSame('committed', $result);
        self::assertSame(3, $attempts);
    }

    #[Test]
    public function it_does_not_retry_a_non_concurrency_failure(): void
    {
        $attempts = 0;
        $caughtException = null;

        try {
            (new PostgresConcurrencyRetrier)->run(
                static function () use (&$attempts): never {
                    $attempts++;

                    throw new RuntimeException('A domain or programming failure.');
                },
            );
        } catch (RuntimeException $exception) {
            $caughtException = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caughtException);
        self::assertSame('A domain or programming failure.', $caughtException->getMessage());
        self::assertSame(1, $attempts);
    }
}
