<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Currency\Port\ExchangeRateProvider;
use App\Domain\Currency\ExchangeRate;
use App\Domain\Shared\ValueObject\Currency;
use App\Infrastructure\Currency\CachedExchangeRateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExchangeRateProviderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_fetches_once_then_uses_cache_and_persists_the_rate_snapshot(): void
    {
        $source = new class implements ExchangeRateProvider
        {
            public int $calls = 0;

            public function quote(Currency $base, Currency $quote): ExchangeRate
            {
                $this->calls++;

                return ExchangeRate::fromDecimal(
                    $base,
                    $quote,
                    '0.925',
                    'counting-fake',
                    '2026-11-23T10:00:00+00:00',
                );
            }
        };
        $cache = Cache::store('array');
        $cache->clear();
        $provider = new CachedExchangeRateProvider($source, $cache);
        $usd = Currency::fromCode('USD');
        $eur = Currency::fromCode('EUR');

        $first = $provider->quote($usd, $eur);
        $second = $provider->quote($usd, $eur);

        self::assertSame(1, $source->calls);
        self::assertSame($first->decimal(), $second->decimal());
        self::assertDatabaseCount('exchange_rates', 1);
        self::assertDatabaseHas('exchange_rates', [
            'base' => 'USD',
            'quote' => 'EUR',
            'rate' => '0.9250000000',
            'source' => 'counting-fake',
        ]);
    }
}
