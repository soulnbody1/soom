<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdReel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AdReelPolicy
{
    public function delete(User $user, AdReel $adReel): Response
    {
        return $user->id === $adReel->ad?->user_id
            ? Response::allow()
            : Response::deny('⚠️ ليس لديك صلاحية لحذف هذا الإعلان.');
    }
}
