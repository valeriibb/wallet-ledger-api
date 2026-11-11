<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registration_creates_a_user_with_a_hashed_password_and_user_role(): void
    {
        $response = $this->postJson('/v1/auth/register', [
            'name' => 'Alice',
            'email' => '  ALICE@example.test ',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'role' => 'admin',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Alice')
            ->assertJsonPath('data.email', 'alice@example.test')
            ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token']);

        $user = User::query()->where('email', 'alice@example.test')->firstOrFail();

        self::assertSame(UserRole::User, $user->role);
        self::assertNotSame('StrongPassword123!', $user->password);
        self::assertTrue(Hash::check('StrongPassword123!', $user->password));
        self::assertSame(1, $user->tokens()->count());

        $plainTextToken = $response->json('token');
        self::assertIsString($plainTextToken);
        [, $secret] = explode('|', $plainTextToken, 2);
        self::assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'token' => hash('sha256', $secret),
        ]);
    }

    #[Test]
    public function login_returns_a_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'alice@example.test',
            'password' => 'StrongPassword123!',
        ]);

        $response = $this->postJson('/v1/auth/login', [
            'email' => 'ALICE@example.test',
            'password' => 'StrongPassword123!',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.email', 'alice@example.test')
            ->assertJsonStructure(['token']);
    }

    #[Test]
    public function wrong_password_and_unknown_email_return_the_same_public_error(): void
    {
        User::factory()->create([
            'email' => 'alice@example.test',
            'password' => 'StrongPassword123!',
        ]);

        $wrongPassword = $this->postJson('/v1/auth/login', [
            'email' => 'alice@example.test',
            'password' => 'WrongPassword123!',
        ]);
        $unknownEmail = $this->postJson('/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'WrongPassword123!',
        ]);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();
        $wrongPasswordBody = $wrongPassword->json();
        $unknownEmailBody = $unknownEmail->json();
        self::assertIsArray($wrongPasswordBody);
        self::assertIsArray($unknownEmailBody);
        self::assertSame(
            Arr::except($wrongPasswordBody, ['request_id']),
            Arr::except($unknownEmailBody, ['request_id']),
        );
    }

    #[Test]
    public function a_bearer_token_authenticates_the_user_endpoint(): void
    {
        $registration = $this->postJson('/v1/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.test',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);

        $this->getJson('/v1/user')->assertUnauthorized();

        $plainTextToken = $registration->json('token');
        self::assertIsString($plainTextToken);

        $this->withToken($plainTextToken)
            ->getJson('/v1/user')
            ->assertOk()
            ->assertJsonPath('email', 'alice@example.test');
    }

    #[Test]
    public function repeated_failed_logins_are_rate_limited(): void
    {
        $email = 'limited-'.Str::uuid().'@example.test';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/v1/auth/login', [
                'email' => $email,
                'password' => 'WrongPassword123!',
            ])->assertUnauthorized();
        }

        $this->postJson('/v1/auth/login', [
            'email' => $email,
            'password' => 'WrongPassword123!',
        ])->assertTooManyRequests();
    }
}
