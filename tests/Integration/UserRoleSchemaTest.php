<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserRoleSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function postgres_rejects_an_unknown_user_role(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Invalid Role',
            'email' => 'invalid-role@example.test',
            'password' => 'not-used',
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
