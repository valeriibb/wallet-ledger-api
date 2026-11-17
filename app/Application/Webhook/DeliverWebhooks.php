<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Application\Webhook\Port\WebhookDeliveryStore;
use App\Application\Webhook\Port\WebhookHttpClient;
use Throwable;

final readonly class DeliverWebhooks
{
    public function __construct(
        private WebhookDeliveryStore $store,
        private WebhookHttpClient $client,
        private WebhookUrlGuard $urlGuard,
        private WebhookSigner $signer,
    ) {}

    public function execute(int $limit = 100): DeliveryBatchResult
    {
        $deliveries = $this->store->claimDue($limit);
        $delivered = 0;
        $failed = 0;

        foreach ($deliveries as $delivery) {
            $timestamp = time();
            $body = json_encode([
                'id' => $delivery->eventId,
                'type' => $delivery->eventType,
                'data' => $delivery->payload,
            ], JSON_THROW_ON_ERROR);

            try {
                $this->urlGuard->assertSafe($delivery->url);
                $response = $this->client->post($delivery->url, [
                    'Content-Type' => 'application/json',
                    'X-Webhook-Event-Id' => $delivery->eventId,
                    'X-Webhook-Timestamp' => (string) $timestamp,
                    'X-Webhook-Signature' => $this->signer->sign(
                        $delivery->secret,
                        $delivery->eventId,
                        $timestamp,
                        $body,
                    ),
                ], $body);

                if ($response->successful()) {
                    $this->store->markDelivered($delivery->id, $response->statusCode);
                    $delivered++;

                    continue;
                }

                $this->store->markFailed(
                    $delivery->id,
                    $delivery->attempt,
                    $response->statusCode,
                    "Webhook returned HTTP {$response->statusCode}.",
                );
            } catch (Throwable $exception) {
                $this->store->markFailed($delivery->id, $delivery->attempt, null, $exception->getMessage());
            }

            $failed++;
        }

        return new DeliveryBatchResult(count($deliveries), $delivered, $failed);
    }
}
