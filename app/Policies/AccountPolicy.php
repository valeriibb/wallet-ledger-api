<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;

final class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::User;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::User;
    }

    public function view(User $user, Account $account): bool
    {
        return $this->ownsUserWallet($user, $account);
    }

    public function debit(User $user, Account $account): bool
    {
        return $this->ownsUserWallet($user, $account);
    }

    private function ownsUserWallet(User $user, Account $account): bool
    {
        return $user->role === UserRole::User
            && $account->type === 'user_wallet'
            && $account->owner_id === $user->id;
    }
}
