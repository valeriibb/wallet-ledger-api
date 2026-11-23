<?php

declare(strict_types=1);

namespace App\Infrastructure\Currency;

use App\Application\Currency\Exception\ExchangeRateUnavailable;
use App\Application\Currency\Port\ExchangeRateProvider;
use App\Domain\Currency\ExchangeRate;
use App\Domain\Shared\ValueObject\Currency;

final class FakeExchangeRateProvider implements ExchangeRateProvider
{
    /** @var array<string, string> */
    private const array RATES = [
        'USD/EUR' => '0.9200000000',
        'EUR/USD' => '1.0869565217',
        'USD/UAH' => '41.2500000000',
        'UAH/USD' => '0.0242424242',
        'EUR/UAH' => '44.8369565217',
        'UAH/EUR' => '0.0223030303',
    ];

    public function quote(Currency $base, Currency $quote): ExchangeRate
    {
        $pair = $base->code().'/'.$quote->code();
        $decimal = $base->equals($quote) ? '1.0000000000' : (self::RATES[$pair] ?? null);

        if ($decimal === null) {
            throw ExchangeRateUnavailable::forPair($base->code(), $quote->code());
        }

        return ExchangeRate::fromDecimal(
            $base,
            $quote,
            $decimal,
            'fake-provider',
            now()->toIso8601String(),
        );
    }
}
