<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Payment;

use App\Application\Payment\ProviderPaymentStatus;
use App\Domain\Shared\ValueObject\Currency;
use App\Domain\Shared\ValueObject\Money;
use App\Infrastructure\Payment\FakePaymentProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakePaymentProviderTest extends TestCase
{
    #[Test]
    public function it_confirms_a_charge_and_replays_the_reference_for_the_same_key(): void
    {
        $provider = new FakePaymentProvider;
        $amount = Money::ofMinor(2500, Currency::fromCode('USD'));

        $first = $provider->charge('user-1', $amount, 'deposit-1');
        $replayed = $provider->charge('user-1', $amount, 'deposit-1');
        $different = $provider->charge('user-1', $amount, 'deposit-2');

        self::assertSame(ProviderPaymentStatus::Confirmed, $first->status);
        self::assertSame($first->providerReference, $replayed->providerReference);
        self::assertNotSame($first->providerReference, $different->providerReference);
    }

    #[Test]
    public function it_confirms_a_payout_and_replays_the_reference_for_the_same_key(): void
    {
        $provider = new FakePaymentProvider;
        $amount = Money::ofMinor(1000, Currency::fromCode('EUR'));

        $first = $provider->payout('user-1', $amount, 'withdrawal-1');
        $replayed = $provider->payout('user-1', $amount, 'withdrawal-1');
        $different = $provider->payout('user-1', $amount, 'withdrawal-2');

        self::assertSame(ProviderPaymentStatus::Confirmed, $first->status);
        self::assertSame($first->providerReference, $replayed->providerReference);
        self::assertNotSame($first->providerReference, $different->providerReference);
        self::assertStringStartsWith('fake_payout_', $first->providerReference);
    }
}
