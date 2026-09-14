<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\Ad;
use App\Models\User;
use App\Models\UserAdInteraction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class UserContentQuery
{
    private const AD_COLUMNS = ['id', 'public_id', 'user_id', 'category_id', 'title', 'price', 'city_id', 'is_featured', 'created_at', 'deleted_at'];

    private const AD_RELATIONS = ['category:id,name', 'city:id,name', 'images:id,ad_id,image_path'];

    public function paginateAds(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->adBase()
            ->where('user_id', $user->id)
            ->when(($filters['status'] ?? null) === 'blocked', fn (Builder $query) => $query->whereNotNull('deleted_at'))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->whereNull('deleted_at'))
            ->when(($filters['featured'] ?? null) !== null, fn (Builder $query) => $query->where('is_featured', (bool) $filters['featured']))
            ->when($filters['category_id'] ?? null, fn (Builder $query, $value) => $query->where('category_id', (int) $value))
            ->when($filters['search'] ?? null, fn (Builder $query, $value) => $query->where('title', 'like', '%'.trim((string) $value).'%'))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateFavorites(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->adBase()
            ->join('favorites', 'favorites.ad_id', '=', 'ads.id')
            ->where('favorites.user_id', $user->id)
            ->addSelect('favorites.created_at as interacted_at')
            ->orderByDesc('favorites.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateSavedAds(User $user, int $perPage): LengthAwarePaginator
    {
        return UserAdInteraction::query()
            ->select(['id', 'user_id', 'ad_id', 'created_at'])
            ->where('user_id', $user->id)
            ->where('action', 'save')
            ->with(['ad' => fn ($query) => $query->withTrashed()->select(self::AD_COLUMNS)->with(self::AD_RELATIONS)])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function adBase(): Builder
    {
        return Ad::withTrashed()
            ->select(array_map(fn (string $column) => 'ads.'.$column, self::AD_COLUMNS))
            ->with(self::AD_RELATIONS)
            ->withCount(['views as views_count', 'favoritedByUsers as favorites_count']);
    }
}
