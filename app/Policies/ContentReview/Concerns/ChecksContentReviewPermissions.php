<?php

declare(strict_types=1);

namespace App\Policies\ContentReview\Concerns;

use App\Models\User;

trait ChecksContentReviewPermissions
{
    protected function hasContentReviewPermission(User $user, string $permission): bool
    {
        $explicit = $user->getAttribute('auction_permissions');

        if (is_array($explicit) && in_array($permission, $explicit, true)) {
            return true;
        }

        return $user->role === 'admin'
            && in_array($permission, config('content_review.role_admin_permissions', []), true);
    }
}
