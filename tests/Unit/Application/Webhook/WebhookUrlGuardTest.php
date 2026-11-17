<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Webhook;

use App\Application\Webhook\Exception\InvalidWebhookUrl;
use App\Application\Webhook\Port\HostResolver;
use App\Application\Webhook\WebhookUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookUrlGuardTest extends TestCase
{
    #[Test]
    public function it_accepts_https_when_every_resolved_address_is_public(): void
    {
        $this->expectNotToPerformAssertions();
        $guard = new WebhookUrlGuard($this->resolver(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']));

        $guard->assertSafe('https://hooks.example.com/events?source=wallet');
    }

    /** @param list<string> $addresses */
    #[Test]
    #[DataProvider('unsafeUrls')]
    public function it_rejects_unsafe_urls(string $url, array $addresses = ['93.184.216.34']): void
    {
        $this->expectException(InvalidWebhookUrl::class);

        (new WebhookUrlGuard($this->resolver($addresses)))->assertSafe($url);
    }

    /** @return iterable<string, array{0: string, 1?: list<string>}> */
    public static function unsafeUrls(): iterable
    {
        yield 'plain HTTP' => ['http://example.com/hook'];
        yield 'credentials' => ['https://user:password@example.com/hook'];
        yield 'loopback literal' => ['https://127.0.0.1/hook'];
        yield 'cloud metadata' => ['https://169.254.169.254/latest/meta-data'];
        yield 'private DNS answer' => ['https://hooks.example.com/hook', ['10.0.0.4']];
        yield 'mixed public and private answers' => ['https://hooks.example.com/hook', ['93.184.216.34', '192.168.1.10']];
        yield 'local domain' => ['https://service.internal/hook'];
        yield 'unresolved domain' => ['https://missing.example/hook', []];
    }

    /** @param list<string> $addresses */
    private function resolver(array $addresses): HostResolver
    {
        return new class($addresses) implements HostResolver
        {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses) {}

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        };
    }
}
