<?php

declare(strict_types=1);

namespace App\Services\User\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class UpdateUserProfileAction
{
    public function execute(User $user, array $data): User
    {
        $previousLogo = null;

        if (isset($data['logo'])) {
            $previousLogo = $user->getRawOriginal('logo');
            $data['logo'] = $data['logo']->store('users', 'spaces');
        }

        $user->update($data);

        if ($previousLogo && $previousLogo !== $user->getRawOriginal('logo')) {
            Storage::disk('spaces')->delete(ltrim($previousLogo, '/'));
        }

        return $user;
    }
}
