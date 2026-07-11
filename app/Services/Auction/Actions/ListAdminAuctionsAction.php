<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminAuctionsAction
{
    public function execute(int $perPage): LengthAwarePaginator
    {
        return Auction::with(['media', 'category', 'seller', 'metric', 'currentLeadingBid', 'winningBid', 'settlement'])
            ->latest('id')
            ->paginate($perPage);
    }
}
