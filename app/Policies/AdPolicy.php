<?php

namespace App\Policies;

use App\Models\Ad;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AdPolicy
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
    public function view(User $user, Ad $ad): bool
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
    public function update(User $user, Ad $ad)
    {
        if ($user->id !== $ad->user_id) {
            abort(response()->json([
                'success' => false,
                'message' => '⚠️ ليس لديك صلاحية لتعديل هذا الإعلان.'
            ], 403));
        }

        return true;
    }


    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Ad $ad): bool
    {
        if ($user->id !== $ad->user_id) {
            abort(response()->json([
                'success' => false,
                'message' => '⚠️ ليس لديك صلاحية لتعطيل هذا الإعلان.'
            ], 403));
        }

        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Ad $ad): bool
    {
        if ($user->id !== $ad->user_id) {
            abort(response()->json([
                'success' => false,
                'message' => '⚠️ ليس لديك صلاحية لتنشيط هذا الإعلان.'
            ], 403));
        }

        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Ad $ad): bool
    {
        if ($user->id !== $ad->user_id) {
            abort(response()->json([
                'success' => false,
                'message' => '⚠️ ليس لديك صلاحية لحذف هذا الإعلان.'
            ], 403));
        }

        return true;
    }
}
