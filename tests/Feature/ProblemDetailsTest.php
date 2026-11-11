<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProblemDetailsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validation_errors_use_problem_details_and_expose_the_request_id(): void
    {
        $response = $this->postJson('/v1/auth/register', []);

        $response
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Unprocessable Content')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('detail', 'One or more fields are invalid.')
            ->assertJsonPath('instance', '/v1/auth/register')
            ->assertJsonStructure(['request_id', 'errors']);

        self::assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function a_valid_client_request_id_is_used_on_success_and_error_responses(): void
    {
        $requestId = (string) Str::uuid();

        $success = $this->withHeader('X-Request-Id', strtoupper($requestId))
            ->postJson('/v1/auth/register', [
                'name' => 'Alice',
                'email' => 'alice@example.test',
                'password' => 'StrongPassword123!',
                'password_confirmation' => 'StrongPassword123!',
            ]);

        $success->assertCreated()->assertHeader('X-Request-Id', $requestId);

        $error = $this->withHeader('X-Request-Id', $requestId)
            ->getJson('/v1/accounts');

        $error
            ->assertUnauthorized()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('request_id', $requestId);
    }

    #[Test]
    public function an_invalid_client_request_id_is_replaced_with_a_uuid_version_seven(): void
    {
        $response = $this->withHeader('X-Request-Id', "invalid\r\nvalue")
            ->getJson('/v1/accounts');

        $generatedRequestId = $response->headers->get('X-Request-Id');

        self::assertIsString($generatedRequestId);
        self::assertTrue(Str::isUuid($generatedRequestId));
        self::assertSame('7', $generatedRequestId[14]);
        $response->assertJsonPath('request_id', $generatedRequestId);
    }

    #[Test]
    public function authorization_not_found_and_conflict_errors_share_the_contract(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/v1/accounts', ['currency' => 'USD'])
            ->assertForbidden()
            ->assertJsonStructure($this->problemStructure());

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/v1/accounts/'.Str::uuid().'/balance')
            ->assertNotFound()
            ->assertJsonStructure($this->problemStructure());

        $this->postJson('/v1/accounts', ['currency' => 'USD'])->assertCreated();
        $this->postJson('/v1/accounts', ['currency' => 'USD'])
            ->assertConflict()
            ->assertJsonStructure($this->problemStructure());
    }

    #[Test]
    public function router_errors_also_use_problem_details(): void
    {
        $this->getJson('/v1/missing')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure($this->problemStructure());

        $this->putJson('/v1/auth/login')
            ->assertStatus(405)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure($this->problemStructure());
    }

    /** @return list<string> */
    private function problemStructure(): array
    {
        return ['type', 'title', 'status', 'detail', 'instance', 'request_id'];
    }
}
