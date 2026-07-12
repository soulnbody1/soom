<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\Auction\RefundTransaction;
use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class AuctionRefundPolicy
{
    use ChecksAuctionPermissions;

    public function execute(User $user, RefundTransaction $refund): bool
    {
        return $this->hasAuctionPermission($user, 'auction.refund.execute');
    }

    public function confirmManual(User $user, RefundTransaction $refund): bool
    {
        return $this->hasAuctionPermission($user, 'auction.refunds.confirm_manual');
    }

    public function cancel(User $user, RefundTransaction $refund): bool
    {
        return $this->hasAuctionPermission($user, 'auction.refunds.cancel');
    }
}
