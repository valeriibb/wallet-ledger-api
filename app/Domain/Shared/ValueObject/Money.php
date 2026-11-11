<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\CurrencyMismatch;
use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    private function __construct(
        private int $minorAmount,
        private Currency $currency,
    ) {}

    public static function ofMinor(int $minorAmount, Currency $currency): self
    {
        if ($minorAmount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        return new self($minorAmount, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function minorAmount(): int
    {
        return $this->minorAmount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->minorAmount === $other->minorAmount
            && $this->currency->equals($other->currency);
    }

    public function add(self $other): self
    {
        $this->ensureSameCurrency($other);

        if ($this->minorAmount > PHP_INT_MAX - $other->minorAmount) {
            throw new OverflowException('Money addition exceeds the integer limit.');
        }

        return new self($this->minorAmount + $other->minorAmount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->ensureSameCurrency($other);

        if ($other->minorAmount > $this->minorAmount) {
            throw new InvalidArgumentException('Money subtraction cannot produce a negative amount.');
        }

        return new self($this->minorAmount - $other->minorAmount, $this->currency);
    }

    /**
     * @return non-empty-list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Money must be allocated to at least one part.');
        }

        $minorAmountPerPart = intdiv($this->minorAmount, $parts);
        $remainder = $this->minorAmount % $parts;
        $allocated = [];

        for ($index = 0; $index < $parts; $index++) {
            $extraMinorUnit = $index < $remainder ? 1 : 0;
            $allocated[] = new self($minorAmountPerPart + $extraMinorUnit, $this->currency);
        }

        return $allocated;
    }

    private function ensureSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw CurrencyMismatch::between(
                $this->currency->code(),
                $other->currency->code(),
            );
        }
    }
}
