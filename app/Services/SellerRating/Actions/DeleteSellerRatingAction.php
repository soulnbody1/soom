<?php

declare(strict_types=1);

namespace App\Services\SellerRating\Actions;

use App\Models\SellerRating;
use App\Models\User;

final class DeleteSellerRatingAction
{
    public function execute(User $reviewer, User $seller): void
    {
        SellerRating::query()
            ->where('seller_id', $seller->id)
            ->where('reviewer_id', $reviewer->id)
            ->firstOrFail()
            ->delete();
    }
}
