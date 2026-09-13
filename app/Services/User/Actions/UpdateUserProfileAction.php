<?php

declare(strict_types=1);

namespace App\Services\User\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class UpdateUserProfileAction
{
    private const PICTURES = ['logo', 'cover'];

    public function execute(User $user, array $data): User
    {
        $previous = [];

        foreach (self::PICTURES as $picture) {
            if (isset($data[$picture])) {
                $previous[$picture] = $user->getRawOriginal($picture);
                $data[$picture] = $data[$picture]->store('users', 'spaces');
            }
        }

        $user->update($data);

        foreach ($previous as $picture => $path) {
            if ($path && $path !== $user->getRawOriginal($picture)) {
                Storage::disk('spaces')->delete(ltrim($path, '/'));
            }
        }

        return $user;
    }
}
