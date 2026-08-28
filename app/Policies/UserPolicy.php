<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class UserPolicy
{
    use ChecksAuctionPermissions;

    public function viewAdminProfile(User $actor, User $target): bool
    {
        return $actor->role === 'admin';
    }

    public function viewFinancialProfile(User $actor, User $target): bool
    {
        return $this->viewAdminProfile($actor, $target)
            || $this->hasAuctionPermission($actor, 'auction.payouts.view');
    }

    public function viewConversations(User $actor, User $target): bool
    {
        return $this->viewAdminProfile($actor, $target);
    }
}
