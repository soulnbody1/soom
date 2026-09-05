<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;
use App\Models\AdView;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class MyAdsQuery
{
    private const PER_PAGE = 20;

    private const MAX_UNPAGINATED = 200;

    public function get(int $ownerId): Collection
    {
        return $this->baseQuery($ownerId)->limit(self::MAX_UNPAGINATED)->get();
    }

    public function paginate(int $ownerId): LengthAwarePaginator
    {
        return $this->baseQuery($ownerId)->paginate(self::PER_PAGE);
    }

    public function totalViews(int $ownerId): int
    {
        return (int) AdView::query()
            ->whereIn('ad_id', Ad::query()->withTrashed()->select('id')->where('user_id', $ownerId))
            ->count();
    }

    private function baseQuery(int $ownerId): Builder
    {
        return Ad::query()
            ->withTrashed()
            ->where('user_id', $ownerId)
            ->withCount('views')
            ->with(Ad::$defaultRelations)
            ->latest();
    }
}
