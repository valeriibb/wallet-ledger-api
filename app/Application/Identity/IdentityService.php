<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class IdentityService
{
    public function register(string $name, string $email, string $password): TokenGrant
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        return $this->issueToken($user);
    }

    public function authenticate(string $email, string $password): ?TokenGrant
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $this->issueToken($user);
    }

    private function issueToken(User $user): TokenGrant
    {
        return new TokenGrant(
            (string) $user->id,
            (string) $user->name,
            (string) $user->email,
            $user->createToken('wallet-api')->plainTextToken,
        );
    }
}
