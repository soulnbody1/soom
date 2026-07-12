<?php

declare(strict_types=1);

namespace App\Policies\Auction;

use App\Models\Auction\PaymentSubmission;
use App\Models\User;
use App\Policies\Auction\Concerns\ChecksAuctionPermissions;

final class PaymentSubmissionPolicy
{
    use ChecksAuctionPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasAuctionPermission($user, 'auction.payment.review');
    }

    public function view(User $user, PaymentSubmission $submission): bool
    {
        return $submission->user_id === $user->id
            || $this->hasAuctionPermission($user, 'auction.payment.review');
    }

    public function viewReceipt(User $user, PaymentSubmission $submission): bool
    {
        return $submission->user_id === $user->id
            || $this->hasAuctionPermission($user, 'auction.payment.review');
    }

    public function approve(User $user, PaymentSubmission $submission): bool
    {
        return $this->hasAuctionPermission($user, 'auction.payment.approve');
    }

    public function reject(User $user, PaymentSubmission $submission): bool
    {
        return $this->hasAuctionPermission($user, 'auction.payment.approve');
    }

    public function overrideDeadline(User $user, PaymentSubmission $submission): bool
    {
        return $this->hasAuctionPermission($user, 'auction.payment.override_deadline');
    }
}
