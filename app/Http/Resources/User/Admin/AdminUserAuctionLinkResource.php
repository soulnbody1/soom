<?php

declare(strict_types=1);

namespace App\Http\Resources\User\Admin;

use App\Models\Auction\Auction;

final class AdminUserAuctionLinkResource
{
    public static function from(?Auction $auction): ?array
    {
        if ($auction === null) {
            return null;
        }

        return [
            'id' => $auction->public_id,
            'title' => $auction->title,
            'status' => $auction->status?->value,
        ];
    }
}
