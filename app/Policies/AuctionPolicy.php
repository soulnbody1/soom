<?php

namespace App\Policies;

use App\Models\Auction;
use App\Models\User;

class AuctionPolicy
{
    public function update(User $user, Auction $auction): bool
    {
        if ($user->id !== $auction->user_id) {
            return false;
        }

        return in_array($auction->status, ['draft', 'pending_payment']);
    }

    public function delete(User $user, Auction $auction): bool
    {
        if ($user->id !== $auction->user_id) {
            return false;
        }

        return in_array($auction->status, ['draft', 'pending_payment']);
    }

    public function close(User $user, Auction $auction): bool
    {
        return $user->id === $auction->user_id;
    }

    public function payDeposit(User $user, Auction $auction): bool
    {
        return $user->id === $auction->user_id;
    }

    public function bid(User $user, Auction $auction): bool
    {
        if ($user->id === $auction->user_id) {
            return false;
        }

        return $auction->isActive();
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Auction $auction): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
