<?php

declare(strict_types=1);

namespace Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenApiContractTest extends TestCase
{
    /** @throws JsonException */
    #[Test]
    public function it_documents_every_public_api_operation_and_the_error_contract(): void
    {
        $contents = file_get_contents(__DIR__.'/../../docs/openapi.json');
        self::assertIsString($contents);

        /** @var array<string, mixed> $document */
        $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('3.1.0', $document['openapi']);
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);

        $operations = [
            ['/v1/auth/register', 'post'],
            ['/v1/auth/login', 'post'],
            ['/v1/user', 'get'],
            ['/v1/accounts', 'get'],
            ['/v1/accounts', 'post'],
            ['/v1/accounts/{account}/balance', 'get'],
            ['/v1/accounts/{account}/entries', 'get'],
            ['/v1/transfers', 'post'],
            ['/v1/deposits', 'post'],
            ['/v1/withdrawals', 'post'],
            ['/v1/transactions/{transaction}/reversal', 'post'],
            ['/v1/webhook-endpoints', 'get'],
            ['/v1/webhook-endpoints', 'post'],
            ['/v1/webhook-endpoints/{webhook}', 'patch'],
            ['/v1/webhook-endpoints/{webhook}', 'delete'],
            ['/v1/webhook-deliveries/{delivery}/replay', 'post'],
            ['/v1/admin/reconciliation/runs', 'get'],
        ];

        foreach ($operations as [$path, $method]) {
            $pathItem = $paths[$path] ?? null;
            self::assertIsArray($pathItem);
            self::assertArrayHasKey($method, $pathItem);
        }

        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $problemDetails = $schemas['ProblemDetails'] ?? null;
        self::assertIsArray($problemDetails);
        self::assertSame(
            ['type', 'title', 'status', 'detail', 'instance', 'request_id'],
            $problemDetails['required'],
        );

        $responses = $components['responses'] ?? null;
        self::assertIsArray($responses);
        $problemResponse = $responses['Problem'] ?? null;
        self::assertIsArray($problemResponse);
        $problemContent = $problemResponse['content'] ?? null;
        self::assertIsArray($problemContent);
        self::assertArrayHasKey(
            'application/problem+json',
            $problemContent,
        );
    }
}
