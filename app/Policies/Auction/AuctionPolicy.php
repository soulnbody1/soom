<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class AuctionPolicy
{
    use ChecksAuctionPermissions;

    public function view(?User $user, Auction $auction): bool
    {
        return $auction->status->isPubliclyVisible()
            || ($user && ($user->id === $auction->seller_id || $this->hasAuctionPermission($user, 'auction.review')));
    }

    public function create(User $user): bool
    {
        return $user->role === 'user' || $user->role === 'admin';
    }

    public function viewAny(User $user): bool
    {
        return $this->hasAuctionPermission($user, 'auction.review');
    }

    public function submitForReview(User $user, Auction $auction): bool
    {
        return $user->id === $auction->seller_id
            && in_array($auction->status, [AuctionStatus::Draft, AuctionStatus::Rejected, AuctionStatus::PendingReview], true);
    }

    public function reopen(User $user, Auction $auction): bool
    {
        return $user->id === $auction->seller_id;
    }

    public function update(User $user, Auction $auction): bool
    {
        return $user->id === $auction->seller_id;
    }

    public function review(User $user, Auction $auction): bool
    {
        return $this->hasAuctionPermission($user, 'auction.review');
    }

    public function approve(User $user, Auction $auction): bool
    {
        return $this->hasAuctionPermission($user, 'auction.approve');
    }

    public function cancel(User $user, Auction $auction): bool
    {
        if ($this->hasAuctionPermission($user, 'auction.cancel.admin')
            || $this->hasAuctionPermission($user, 'auction.cancel.compliance')
            || $this->hasAuctionPermission($user, 'auction.cancel')) {
            return true;
        }

        return $user->id === $auction->seller_id
            && in_array($auction->status, [
                AuctionStatus::Draft,
                AuctionStatus::PendingReview,
                AuctionStatus::Rejected,
                AuctionStatus::AwaitingSellerDeposit,
                AuctionStatus::Scheduled,
                AuctionStatus::Live,
                AuctionStatus::Ended,
                AuctionStatus::SettlementPending,
                AuctionStatus::PaymentPending,
            ], true);
    }

    public function register(User $user, Auction $auction): bool
    {
        return $user->id !== $auction->seller_id
            && in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true);
    }

    public function endEarly(User $user, Auction $auction): bool
    {
        if ($auction->status !== AuctionStatus::Live) {
            return false;
        }

        return $user->id === $auction->seller_id
            || $this->hasAuctionPermission($user, 'auction.end_early');
    }

    public function bid(User $user, Auction $auction): bool
    {
        return $user->id !== $auction->seller_id && $auction->status === AuctionStatus::Live;
    }

    public function confirmSellerHandover(User $user, Auction $auction): bool
    {
        return $user->id === $auction->seller_id
            && $auction->status === AuctionStatus::HandoverPending;
    }

    public function confirmWinnerReceipt(User $user, Auction $auction): bool
    {
        return $auction->status === AuctionStatus::HandoverPending
            && $auction->settlement?->winner_id === $user->id;
    }

    public function openDispute(User $user, Auction $auction): bool
    {
        $allowedStatuses = [AuctionStatus::Live, AuctionStatus::Ended, AuctionStatus::HandoverPending, AuctionStatus::PaymentPending];
        if (! in_array($auction->status, $allowedStatuses, true)) {
            return false;
        }

        return $user->id === $auction->seller_id
            || $auction->settlement?->winner_id === $user->id;
    }

    public function resolveDispute(User $user, Auction $auction): bool
    {
        return $this->hasAuctionPermission($user, 'auction.dispute.resolve');
    }

    public function markWinnerDefaulted(User $user, Auction $auction): bool
    {
        return $this->hasAuctionPermission($user, 'auction.winners.mark_defaulted');
    }

    public function blockParticipant(User $user, Auction $auction): bool
    {
        return $this->hasAuctionPermission($user, 'auction.participants.block');
    }
}
