<?php

declare(strict_types=1);

namespace App\Infrastructure\Currency;

use App\Application\Currency\Port\ExchangeRateProvider;
use App\Domain\Currency\ExchangeRate;
use App\Domain\Shared\ValueObject\Currency;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CachedExchangeRateProvider implements ExchangeRateProvider
{
    private const int CACHE_SECONDS = 300;

    public function __construct(
        private ExchangeRateProvider $source,
        private Repository $cache,
    ) {}

    public function quote(Currency $base, Currency $quote): ExchangeRate
    {
        $key = 'exchange-rate:v1:'.$base->code().':'.$quote->code();
        $cached = $this->load($key, $base, $quote);

        if (! is_array($cached)
            || ! is_string($cached['base'] ?? null)
            || ! is_string($cached['quote'] ?? null)
            || ! is_string($cached['rate'] ?? null)
            || ! is_string($cached['source'] ?? null)
            || ! is_string($cached['valid_from'] ?? null)) {
            throw new RuntimeException('The exchange-rate cache returned invalid data.');
        }

        if ($cached['base'] !== $base->code() || $cached['quote'] !== $quote->code()) {
            throw new RuntimeException('The exchange-rate cache returned the wrong currency pair.');
        }

        return ExchangeRate::fromDecimal(
            $base,
            $quote,
            $cached['rate'],
            $cached['source'],
            $cached['valid_from'],
        );
    }

    private function load(string $key, Currency $base, Currency $quote): mixed
    {
        return $this->cache->remember($key, self::CACHE_SECONDS, function () use ($base, $quote): array {
            $rate = $this->source->quote($base, $quote);

            DB::table('exchange_rates')->insert([
                'id' => (string) Str::uuid7(),
                'base' => $rate->base()->code(),
                'quote' => $rate->quote()->code(),
                'rate' => $rate->decimal(),
                'source' => $rate->source(),
                'valid_from' => $rate->validFrom(),
            ]);

            return [
                'base' => $rate->base()->code(),
                'quote' => $rate->quote()->code(),
                'rate' => $rate->decimal(),
                'source' => $rate->source(),
                'valid_from' => $rate->validFrom(),
            ];
        });
    }
}
