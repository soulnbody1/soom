<?php

namespace App\Policies;

use App\Models\AdReel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AdReelPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AdReel $adReel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AdReel $adReel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AdReel $adReel)
    {
        if ($user->id !== $adReel->ad->user_id) {
            abort(response()->json([
                'success' => false,
                'message' => '⚠️ ليس لديك صلاحية لحذف هذا الإعلان.'
            ], 403));
        }
    
        return true;
    }
    

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AdReel $adReel): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AdReel $adReel): bool
    {
        return false;
    }
}
