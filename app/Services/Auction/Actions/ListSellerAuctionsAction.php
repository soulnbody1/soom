<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListSellerAuctionsAction
{
    public function execute(int $sellerId, int $perPage): LengthAwarePaginator
    {
        return Auction::with(['media', 'metric', 'currentLeadingBid', 'settlement'])
            ->where('seller_id', $sellerId)
            ->latest('id')
            ->paginate($perPage);
    }
}
