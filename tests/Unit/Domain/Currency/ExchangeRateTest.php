<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Currency;

use App\Domain\Currency\ExchangeRate;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExchangeRateTest extends TestCase
{
    #[Test]
    public function it_converts_minor_units_with_half_up_rounding(): void
    {
        $rate = ExchangeRate::fromDecimal(
            Currency::fromCode('USD'),
            Currency::fromCode('EUR'),
            '0.925',
            'test',
            '2026-11-23T10:00:00+00:00',
        );

        $converted = $rate->convert(Money::ofMinor(100, Currency::fromCode('USD')));

        self::assertSame('0.9250000000', $rate->decimal());
        self::assertSame(93, $converted->minorAmount());
        self::assertSame('EUR', $converted->currency()->code());
    }

    #[Test]
    public function it_reports_the_exact_output_and_rounding_difference(): void
    {
        $usd = Currency::fromCode('USD');
        $rate = ExchangeRate::fromDecimal(
            $usd,
            Currency::fromCode('EUR'),
            '0.925',
            'test',
            '2026-11-23T10:00:00+00:00',
        );

        $converted = $rate->convertDetailed(Money::ofMinor(100, $usd));

        self::assertSame(93, $converted->money->minorAmount());
        self::assertSame('92.5000000000', $converted->exactMinorAmount);
        self::assertSame('0.5000000000', $converted->roundingDifference);
    }

    #[Test]
    public function a_fraction_below_half_rounds_down(): void
    {
        $rate = ExchangeRate::fromDecimal(
            Currency::fromCode('USD'),
            Currency::fromCode('EUR'),
            '0.4999999999',
            'test',
            '2026-11-23T10:00:00+00:00',
        );

        self::assertSame(0, $rate->convert(Money::ofMinor(1, Currency::fromCode('USD')))->minorAmount());
    }

    #[Test]
    public function it_rejects_zero_malformed_and_over_precise_rates(): void
    {
        foreach (['0', '-1.2', '1.00000000001', '1e-3'] as $invalid) {
            try {
                ExchangeRate::fromDecimal(
                    Currency::fromCode('USD'),
                    Currency::fromCode('EUR'),
                    $invalid,
                    'test',
                    '2026-11-23T10:00:00+00:00',
                );
                self::fail("Rate {$invalid} should be rejected.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function money_must_match_the_rate_base_currency(): void
    {
        $rate = ExchangeRate::fromDecimal(
            Currency::fromCode('USD'),
            Currency::fromCode('EUR'),
            '0.92',
            'test',
            '2026-11-23T10:00:00+00:00',
        );

        $this->expectException(InvalidArgumentException::class);
        $rate->convert(Money::ofMinor(100, Currency::fromCode('UAH')));
    }
}
