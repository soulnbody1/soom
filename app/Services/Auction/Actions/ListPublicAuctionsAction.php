<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPublicAuctionsAction
{
    public function execute(?int $categoryId, int $perPage): LengthAwarePaginator
    {
        return Auction::query()
            ->public()
            ->with(['media', 'category', 'metric', 'currentLeadingBid'])
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->latest('id')
            ->paginate($perPage);
    }
}
