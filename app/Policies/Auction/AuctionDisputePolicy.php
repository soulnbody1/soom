<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class AuctionDisputePolicy
{
    use ChecksAuctionPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasAuctionPermission($user, 'auction.dispute.resolve');
    }
}
