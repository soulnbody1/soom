<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\Auction\RefundTransaction;
use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class AuctionRefundPolicy
{
    use ChecksAuctionPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canManageRefunds($user)
            || $this->hasAuctionPermission($user, 'auction.refunds.confirm_manual')
            || $this->hasAuctionPermission($user, 'auction.refunds.cancel');
    }

    public function execute(User $user, RefundTransaction $refund): bool
    {
        return $this->hasAuctionPermission($user, 'auction.refund.execute');
    }

    public function confirmManual(User $user, RefundTransaction $refund): bool
    {
        return $this->canManageRefunds($user)
            || $this->hasAuctionPermission($user, 'auction.refunds.confirm_manual');
    }

    public function viewProof(User $user, RefundTransaction $refund): bool
    {
        return $this->viewAny($user);
    }

    public function cancel(User $user, RefundTransaction $refund): bool
    {
        return $this->canManageRefunds($user)
            || $this->hasAuctionPermission($user, 'auction.refunds.cancel');
    }

    private function canManageRefunds(User $user): bool
    {
        return $this->hasAuctionPermission($user, 'auction.refunds.manage');
    }
}
