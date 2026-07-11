<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\Auction\AuctionSettlement;
use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class AuctionSettlementPolicy
{
    use ChecksAuctionPermissions;

    public function view(User $user, AuctionSettlement $settlement): bool
    {
        return $settlement->winner_id === $user->id
            || $settlement->auction?->seller_id === $user->id
            || $this->hasAuctionPermission($user, 'auction.settlement.override');
    }

    public function override(User $user, AuctionSettlement $settlement): bool
    {
        return $this->hasAuctionPermission($user, 'auction.settlement.override');
    }
}
