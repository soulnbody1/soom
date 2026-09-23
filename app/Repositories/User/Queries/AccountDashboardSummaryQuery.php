<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\Ad;
use App\Models\Auction\Auction;
use App\Models\Favorite;
use App\Repositories\Ad\Queries\MyAdsQuery;

final class AccountDashboardSummaryQuery
{
    public function __construct(private readonly MyAdsQuery $ads) {}

    public function forUser(int $userId): array
    {
        $statusCounts = $this->ads->statusCounts($userId);

        return [
            'active_ads_count' => $statusCounts['active'],
            'deleted_ads_count' => $statusCounts['deleted'],
            'total_ad_views' => $this->ads->totalViews($userId),
            'favorites_count' => Favorite::query()->where('user_id', $userId)->whereHas('ad')->count(),
            'seller_auctions_count' => Auction::query()->where('seller_id', $userId)->count(),
            'recent_ads' => Ad::query()->withTrashed()->where('user_id', $userId)
                ->withCount('views')->with(['images:id,ad_id,image_path', 'market:id,code,web_host'])
                ->latest()->limit(3)->get(),
        ];
    }
}
