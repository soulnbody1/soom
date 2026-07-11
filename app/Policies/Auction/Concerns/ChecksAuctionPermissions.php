<?php

declare(strict_types=1);

namespace App\Policies\Auction\Concerns;

use App\Models\User;

trait ChecksAuctionPermissions
{
    protected function hasAuctionPermission(User $user, string $permission): bool
    {
        $explicit = $user->getAttribute('auction_permissions');

        if (is_array($explicit) && in_array($permission, $explicit, true)) {
            return true;
        }

        return $user->role === 'admin'
            && in_array($permission, config('auction.admin_permissions', []), true);
    }
}
