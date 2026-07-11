<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Models\Auction\AuctionTermsVersion;
use Illuminate\Support\Collection;

final class ListAuctionTermsAction
{
    public function execute(): Collection
    {
        return AuctionTermsVersion::latest('version_number')->get()->map(fn (AuctionTermsVersion $terms) => [
            'id' => $terms->public_id,
            'version_number' => $terms->version_number,
            'title' => $terms->title,
            'is_active' => $terms->is_active,
            'published_at' => $terms->published_at?->toIso8601String(),
        ]);
    }
}
