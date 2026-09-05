<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ad;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AdPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Ad $ad): Response
    {
        return $this->owns($user, $ad, '⚠️ ليس لديك صلاحية لتعديل هذا الإعلان.');
    }

    public function delete(User $user, Ad $ad): Response
    {
        return $this->owns($user, $ad, '⚠️ ليس لديك صلاحية لتعطيل هذا الإعلان.');
    }

    public function restore(User $user, Ad $ad): Response
    {
        return $this->owns($user, $ad, '⚠️ ليس لديك صلاحية لتنشيط هذا الإعلان.');
    }

    public function forceDelete(User $user, Ad $ad): Response
    {
        return $this->owns($user, $ad, '⚠️ ليس لديك صلاحية لحذف هذا الإعلان.');
    }

    private function owns(User $user, Ad $ad, string $message): Response
    {
        return $user->id === $ad->user_id
            ? Response::allow()
            : Response::deny($message);
    }
}
