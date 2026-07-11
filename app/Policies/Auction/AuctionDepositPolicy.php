<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\Auction\AuctionDeposit;
use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class AuctionDepositPolicy
{
    use ChecksAuctionPermissions;

    public function view(User $user, AuctionDeposit $deposit): bool
    {
        return $deposit->user_id === $user->id
            || $this->hasAuctionPermission($user, 'auction.payment.review');
    }
}
