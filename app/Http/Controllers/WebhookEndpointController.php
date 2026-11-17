<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Webhook\Exception\InvalidWebhookUrl;
use App\Application\Webhook\Exception\WebhookDeliveryNotFound;
use App\Application\Webhook\Exception\WebhookEndpointNotFound;
use App\Application\Webhook\ManageWebhookEndpoints;
use App\Application\Webhook\Port\WebhookDeliveryStore;
use App\Application\Webhook\WebhookEndpoint;
use App\Http\ProblemDetails;
use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Http\Requests\UpdateWebhookEndpointRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class WebhookEndpointController
{
    public function __construct(
        private ManageWebhookEndpoints $webhooks,
        private WebhookDeliveryStore $deliveries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => array_map(
            $this->endpointData(...),
            $this->webhooks->list($user->id),
        )]);
    }

    public function store(StoreWebhookEndpointRequest $request): JsonResponse
    {
        /** @var array{url: string, events: list<string>} $data */
        $data = $request->validated();

        try {
            $created = $this->webhooks->create($this->user($request)->id, $data['url'], $data['events']);
        } catch (InvalidWebhookUrl $exception) {
            return $this->invalidUrl($request, $exception);
        }

        return response()->json(['data' => [
            ...$this->endpointData($created->endpoint),
            'signing_secret' => $created->signingSecret,
        ]], Response::HTTP_CREATED);
    }

    public function update(UpdateWebhookEndpointRequest $request, string $webhook): JsonResponse
    {
        /** @var array{url?: string, events?: list<string>, is_active?: bool} $data */
        $data = $request->validated();

        try {
            $endpoint = $this->webhooks->update(
                $this->user($request)->id,
                $webhook,
                $data['url'] ?? null,
                $data['events'] ?? null,
                $data['is_active'] ?? null,
            );
        } catch (InvalidWebhookUrl $exception) {
            return $this->invalidUrl($request, $exception);
        } catch (WebhookEndpointNotFound) {
            return $this->notFound($request);
        }

        return response()->json(['data' => $this->endpointData($endpoint)]);
    }

    public function destroy(Request $request, string $webhook): Response
    {
        try {
            $this->webhooks->delete($this->user($request)->id, $webhook);
        } catch (WebhookEndpointNotFound) {
            return $this->notFound($request);
        }

        return response()->noContent();
    }

    public function replay(Request $request, string $delivery): JsonResponse
    {
        try {
            $this->deliveries->replay($this->user($request)->id, $delivery);
        } catch (WebhookDeliveryNotFound) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_NOT_FOUND,
                'Not Found',
                'The webhook delivery was not found.',
            );
        }

        return response()->json(['data' => ['delivery_id' => $delivery, 'status' => 'pending']]);
    }

    /** @return array{id: string, url: string, events: list<string>, is_active: bool, created_at: string} */
    private function endpointData(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'is_active' => $endpoint->isActive,
            'created_at' => $endpoint->createdAt,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return $user;
    }

    private function invalidUrl(Request $request, InvalidWebhookUrl $exception): JsonResponse
    {
        return ProblemDetails::response(
            $request,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Unprocessable Content',
            $exception->getMessage(),
        );
    }

    private function notFound(Request $request): JsonResponse
    {
        return ProblemDetails::response(
            $request,
            Response::HTTP_NOT_FOUND,
            'Not Found',
            'The webhook endpoint was not found.',
        );
    }
}
