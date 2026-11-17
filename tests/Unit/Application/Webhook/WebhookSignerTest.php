<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Webhook;

use App\Application\Webhook\WebhookSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookSignerTest extends TestCase
{
    #[Test]
    public function it_signs_the_timestamp_event_id_and_exact_body(): void
    {
        $signature = (new WebhookSigner)->sign('secret', 'event-1', 1_700_000_000, '{"amount":100}');

        self::assertSame(
            'v1='.hash_hmac('sha256', '1700000000.event-1.{"amount":100}', 'secret'),
            $signature,
        );
    }
}
