<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user;
    }
}
