<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObject;

use App\Domain\Shared\ValueObject\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    #[Test]
    public function it_is_created_from_a_valid_code(): void
    {
        $currency = Currency::fromCode('USD');

        self::assertSame('USD', $currency->code());
        self::assertSame('USD', (string) $currency);
    }

    #[Test]
    public function equality_depends_on_the_code(): void
    {
        self::assertTrue(Currency::fromCode('USD')->equals(Currency::fromCode('USD')));
        self::assertFalse(Currency::fromCode('USD')->equals(Currency::fromCode('EUR')));
    }

    #[Test]
    #[DataProvider('invalidCodes')]
    public function it_rejects_an_invalid_code(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency must contain exactly three uppercase ASCII letters.');

        Currency::fromCode($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'lowercase' => ['usd'];
        yield 'too short' => ['US'];
        yield 'too long' => ['USDT'];
        yield 'numeric character' => ['US1'];
        yield 'non-ASCII letter' => ['ЄВР'];
        yield 'surrounding whitespace' => [' USD '];
    }
}
