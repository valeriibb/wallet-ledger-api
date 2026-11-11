<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\CurrencyMismatch;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private Currency $usd;

    protected function setUp(): void
    {
        $this->usd = Currency::fromCode('USD');
    }

    #[Test]
    public function it_is_created_in_minor_units(): void
    {
        $money = Money::ofMinor(1050, $this->usd);

        self::assertSame(1050, $money->minorAmount());
        self::assertTrue($this->usd->equals($money->currency()));
    }

    #[Test]
    public function it_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative.');

        Money::ofMinor(-1, $this->usd);
    }

    #[Test]
    public function zero_creates_zero_in_the_requested_currency(): void
    {
        self::assertTrue(Money::ofMinor(0, $this->usd)->equals(Money::zero($this->usd)));
    }

    #[Test]
    public function it_adds_money_with_the_same_currency(): void
    {
        $result = Money::ofMinor(700, $this->usd)->add(Money::ofMinor(350, $this->usd));

        self::assertSame(1050, $result->minorAmount());
    }

    #[Test]
    public function it_subtracts_money_with_the_same_currency(): void
    {
        $result = Money::ofMinor(1050, $this->usd)->subtract(Money::ofMinor(350, $this->usd));

        self::assertSame(700, $result->minorAmount());
    }

    #[Test]
    public function subtraction_can_result_in_zero(): void
    {
        $result = Money::ofMinor(250, $this->usd)->subtract(Money::ofMinor(250, $this->usd));

        self::assertSame(0, $result->minorAmount());
        self::assertTrue($this->usd->equals($result->currency()));
    }

    #[Test]
    public function arithmetic_does_not_modify_the_original_values(): void
    {
        $left = Money::ofMinor(700, $this->usd);
        $right = Money::ofMinor(350, $this->usd);

        $left->add($right);

        self::assertSame(700, $left->minorAmount());
        self::assertSame(350, $right->minorAmount());
    }

    #[Test]
    public function it_rejects_arithmetic_with_different_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->expectExceptionMessage('Cannot calculate money in USD and EUR.');

        Money::ofMinor(100, $this->usd)->add(
            Money::ofMinor(100, Currency::fromCode('EUR')),
        );
    }

    #[Test]
    public function it_rejects_subtraction_with_different_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);
        $this->expectExceptionMessage('Cannot calculate money in USD and EUR.');

        Money::ofMinor(100, $this->usd)->subtract(
            Money::ofMinor(1, Currency::fromCode('EUR')),
        );
    }

    #[Test]
    public function subtraction_cannot_produce_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money subtraction cannot produce a negative amount.');

        Money::ofMinor(100, $this->usd)->subtract(Money::ofMinor(101, $this->usd));
    }

    #[Test]
    public function addition_detects_integer_overflow(): void
    {
        $this->expectException(OverflowException::class);
        $this->expectExceptionMessage('Money addition exceeds the integer limit.');

        Money::ofMinor(PHP_INT_MAX, $this->usd)->add(Money::ofMinor(1, $this->usd));
    }

    #[Test]
    public function addition_can_reach_the_integer_limit_without_overflow(): void
    {
        $result = Money::ofMinor(PHP_INT_MAX - 1, $this->usd)->add(Money::ofMinor(1, $this->usd));

        self::assertSame(PHP_INT_MAX, $result->minorAmount());
    }

    #[Test]
    public function it_allocates_remainder_units_in_a_stable_order(): void
    {
        $parts = Money::ofMinor(10, $this->usd)->allocate(3);

        self::assertSame([4, 3, 3], array_map(
            static fn (Money $money): int => $money->minorAmount(),
            $parts,
        ));
    }

    #[Test]
    public function it_can_allocate_less_money_than_the_number_of_parts(): void
    {
        $parts = Money::ofMinor(2, $this->usd)->allocate(5);

        self::assertSame([1, 1, 0, 0, 0], array_map(
            static fn (Money $money): int => $money->minorAmount(),
            $parts,
        ));
    }

    #[Test]
    public function allocation_requires_at_least_one_part(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money must be allocated to at least one part.');

        Money::ofMinor(100, $this->usd)->allocate(0);
    }
}
