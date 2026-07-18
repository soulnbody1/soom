<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class AuctionDashboardPolicy
{
    use ChecksAuctionPermissions;

    public function view(User $user): bool
    {
        return $this->hasAuctionPermission($user, 'auction.dashboard.view');
    }
}
