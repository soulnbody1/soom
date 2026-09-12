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

    /**
     * Live and temporarily deleted listings counted separately.
     *
     * The paginator's own `total` counts both, because the list shows both. A
     * seller reading "my listings" means the live ones, so the two figures are
     * reported apart rather than leaving the client to infer either from a page
     * of results it may only partly hold.
     *
     * One grouped aggregate, not two queries: `deleted_at` is indexed and the
     * owner filter is the same for both halves.
     *
     * @return array{active: int, deleted: int}
     */
    public function statusCounts(int $ownerId): array
    {
        $rows = Ad::query()
            ->withTrashed()
            ->where('user_id', $ownerId)
            ->selectRaw('deleted_at IS NULL as is_active, COUNT(*) as aggregate')
            ->groupBy('is_active')
            ->pluck('aggregate', 'is_active');

        return [
            'active' => (int) ($rows[1] ?? $rows['1'] ?? 0),
            'deleted' => (int) ($rows[0] ?? $rows['0'] ?? 0),
        ];
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
