<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Application\Webhook\Exception\InvalidWebhookUrl;
use App\Application\Webhook\Port\HostResolver;

final readonly class WebhookUrlGuard
{
    public function __construct(private HostResolver $resolver) {}

    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidWebhookUrl('A webhook URL must use HTTPS and cannot contain credentials.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if ($host === ''
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            throw new InvalidWebhookUrl('A webhook URL cannot target a local hostname.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($host);

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidWebhookUrl('The webhook hostname is invalid.');
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new InvalidWebhookUrl('The webhook hostname could not be resolved.');
        }

        foreach ($addresses as $address) {
            $this->assertPublicIp($address);
        }
    }

    private function assertPublicIp(string $address): void
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            throw new InvalidWebhookUrl('A webhook URL cannot target a private or reserved address.');
        }
    }
}
