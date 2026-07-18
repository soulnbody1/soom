<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\Auction\AuctionSellerPayout;
use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class SellerPayoutPolicy
{
    use ChecksAuctionPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canView($user) || $this->canManage($user);
    }

    public function view(User $user, AuctionSellerPayout $payout): bool
    {
        return $user->id === $payout->seller_id
            || $this->canView($user)
            || $this->canManage($user);
    }

    public function process(User $user, AuctionSellerPayout $payout): bool
    {
        return $this->canManage($user);
    }

    private function canView(User $user): bool
    {
        return $this->hasAuctionPermission($user, 'auction.payouts.view');
    }

    private function canManage(User $user): bool
    {
        return $this->hasAuctionPermission($user, 'auction.payouts.manage');
    }
}
