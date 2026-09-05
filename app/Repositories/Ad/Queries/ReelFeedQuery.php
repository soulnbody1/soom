<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\AdReel;
use App\Services\Ad\Support\FavoriteFlagHydrator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;

final class ReelFeedQuery
{
    private const PER_PAGE = 10;

    private const WINDOW_DAYS = 1;

    private const FEED_RELATIONS = [
        'ad:id,user_id,category_id,state_id,title,description,price',
        'ad.user:id,name,logo,phone',
    ];

    private const OWN_RELATIONS = [
        'ad:id,user_id,category_id,title,description,price',
        'ad.user:id,name,logo',
    ];

    public function __construct(private readonly FavoriteFlagHydrator $favorites) {}

    public function build(?object $viewer, ?array $categoryIds = null): array
    {
        $viewerId = $viewer?->id;

        return [
            'all_reels' => $this->feed($viewer, $categoryIds),
            'my_reels' => $viewerId === null ? [] : $this->ownReels($viewerId, $categoryIds),
        ];
    }

    private function feed(?object $viewer, ?array $categoryIds): LengthAwarePaginator
    {
        $viewerId = $viewer?->id;
        $viewerStateId = $viewer?->state_id;

        $reels = AdReel::query()
            ->select('ad_reels.*')
            ->where('ad_reels.created_at', '>=', $this->since())
            ->whereHas('ad', function (Builder $ad) use ($viewerId, $viewerStateId, $categoryIds): void {
                $ad->whereNull('deleted_at');

                if ($categoryIds !== null) {
                    $ad->whereIn('category_id', $categoryIds);
                }

                if ($viewerStateId) {
                    $ad->where('state_id', $viewerStateId);
                }

                if ($viewerId) {
                    $ad->where('user_id', '!=', $viewerId);
                }
            })
            ->with(self::FEED_RELATIONS)
            ->when($viewerId, fn (Builder $query): Builder => $this->unseenFirst($query, $viewerId))
            ->latest('ad_reels.created_at')
            ->paginate(self::PER_PAGE);

        return $this->markFavorites($reels, $viewerId);
    }

    private function unseenFirst(Builder $query, int $viewerId): Builder
    {
        return $query
            ->withCount([
                'views as viewed_by_user' => fn ($views) => $views->where('user_id', $viewerId),
            ])
            ->leftJoin('ad_reel_views as viewer_views', function (JoinClause $join) use ($viewerId): void {
                $join->on('viewer_views.ad_reel_id', '=', 'ad_reels.id')
                    ->where('viewer_views.user_id', '=', $viewerId);
            })
            ->orderByRaw('viewer_views.id IS NULL DESC');
    }

    private function markFavorites(LengthAwarePaginator $reels, ?int $viewerId): LengthAwarePaginator
    {
        $adIds = $reels->getCollection()
            ->map(static fn (AdReel $reel): ?int => $reel->ad?->id)
            ->filter()
            ->map(static fn ($adId): int => (int) $adId)
            ->all();

        $favorited = $this->favorites->favoritedAdIds($viewerId, array_values($adIds));

        $reels->getCollection()->transform(function (AdReel $reel) use ($favorited): AdReel {
            $reel->is_favorit = isset($favorited[(int) $reel->ad?->id]) ? 1 : 0;

            return $reel;
        });

        return $reels;
    }

    private function ownReels(int $viewerId, ?array $categoryIds): Collection
    {
        return AdReel::query()
            ->whereHas('ad', function (Builder $ad) use ($viewerId, $categoryIds): void {
                $ad->where('user_id', $viewerId)->whereNull('deleted_at');

                if ($categoryIds !== null) {
                    $ad->whereIn('category_id', $categoryIds);
                }
            })
            ->where('created_at', '>=', $this->since())
            ->with(self::OWN_RELATIONS)
            ->withCount('views')
            ->latest()
            ->get();
    }

    private function since(): Carbon
    {
        return Carbon::now()->subDays(self::WINDOW_DAYS);
    }
}
