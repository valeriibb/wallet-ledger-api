<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    #[Test]
    public function it_hides_sensitive_attributes_from_serialization(): void
    {
        $user = new User;
        $user->setRawAttributes([
            'name' => 'Interview Candidate',
            'email' => 'candidate@example.com',
            'password' => 'secret',
        ]);

        self::assertSame(
            ['name' => 'Interview Candidate', 'email' => 'candidate@example.com'],
            $user->toArray(),
        );
    }
}
