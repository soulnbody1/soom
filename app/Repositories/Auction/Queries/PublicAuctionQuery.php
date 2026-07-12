<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PublicAuctionQuery
{
    private const LIST_RELATIONS = [
        'media',
        'category',
        'metric',
        'currentLeadingBid',
    ];

    private const DETAILS_RELATIONS = [
        'media',
        'category',
        'country',
        'state',
        'city',
        'metric',
        'currentLeadingBid',
    ];

    /**
     * Paginate publicly visible auctions with optional category filter.
     * Replaces ListPublicAuctionsAction query.
     */
    public function paginate(?int $categoryId, int $perPage): LengthAwarePaginator
    {
        return Auction::query()
            ->public()
            ->with(self::LIST_RELATIONS)
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Load full auction details with all display relations.
     * Replaces LoadAuctionDetailsAction.
     */
    public function loadDetails(Auction $auction): Auction
    {
        return $auction->load(self::DETAILS_RELATIONS);
    }
}
