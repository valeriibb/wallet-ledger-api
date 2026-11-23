<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;
use OverflowException;

final readonly class ExchangeRate
{
    private const string SCALE_FACTOR = '10000000000';

    private const string HALF_SCALE = '5000000000';

    private function __construct(
        private Currency $base,
        private Currency $quote,
        private string $decimal,
        private string $source,
        private string $validFrom,
    ) {}

    public static function fromDecimal(
        Currency $base,
        Currency $quote,
        string $decimal,
        string $source,
        string $validFrom,
    ): self {
        if (preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,10}))?$/D', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException('Exchange rate must be a positive decimal with at most 10 fraction digits.');
        }

        $fraction = $matches[2] ?? '';
        /** @var non-falsy-string&numeric-string $normalized */
        $normalized = $matches[1].'.'.str_pad($fraction, 10, '0');

        if (bccomp($normalized, '0', 10) <= 0) {
            throw new InvalidArgumentException('Exchange rate must be greater than zero.');
        }

        if ($source === '' || $validFrom === '') {
            throw new InvalidArgumentException('Exchange rate source and validity time are required.');
        }

        return new self($base, $quote, $normalized, $source, $validFrom);
    }

    public function convert(Money $money): Money
    {
        if (! $money->currency()->equals($this->base)) {
            throw new InvalidArgumentException('Money currency must match the exchange rate base currency.');
        }

        /** @var numeric-string $scaledRate */
        $scaledRate = str_replace('.', '', $this->decimal);
        $product = bcmul((string) $money->minorAmount(), $scaledRate, 0);
        $converted = bcdiv($product, self::SCALE_FACTOR, 0);
        $remainder = bcmod($product, self::SCALE_FACTOR);

        if (bccomp($remainder, self::HALF_SCALE, 0) >= 0) {
            $converted = bcadd($converted, '1', 0);
        }

        if (bccomp($converted, (string) PHP_INT_MAX, 0) > 0) {
            throw new OverflowException('Converted money exceeds the integer limit.');
        }

        return Money::ofMinor((int) $converted, $this->quote);
    }

    public function base(): Currency
    {
        return $this->base;
    }

    public function quote(): Currency
    {
        return $this->quote;
    }

    public function decimal(): string
    {
        return $this->decimal;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function validFrom(): string
    {
        return $this->validFrom;
    }
}
