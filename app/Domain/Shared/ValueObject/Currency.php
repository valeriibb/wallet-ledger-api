<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use InvalidArgumentException;
use Stringable;

final readonly class Currency implements Stringable
{
    private function __construct(private string $code) {}

    public static function fromCode(string $code): self
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Currency must contain exactly three uppercase ASCII letters.');
        }

        return new self($code);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
