<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObject;

use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyAllocationPropertyTest extends TestCase
{
    #[Test]
    public function allocation_preserves_every_minor_unit_for_many_inputs(): void
    {
        $currency = Currency::fromCode('USD');

        for ($minorAmount = 0; $minorAmount <= 500; $minorAmount++) {
            for ($partCount = 1; $partCount <= 20; $partCount++) {
                $allocated = Money::ofMinor($minorAmount, $currency)->allocate($partCount);
                $amounts = array_map(
                    static fn (Money $money): int => $money->minorAmount(),
                    $allocated,
                );

                self::assertCount($partCount, $allocated);
                self::assertSame($minorAmount, array_sum($amounts));
                self::assertLessThanOrEqual(1, max($amounts) - min($amounts));

                foreach ($allocated as $part) {
                    self::assertTrue($currency->equals($part->currency()));
                }
            }
        }
    }
}
