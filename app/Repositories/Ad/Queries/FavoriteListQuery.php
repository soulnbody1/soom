<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;
use App\Models\Favorite;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class FavoriteListQuery
{
    private const PER_PAGE = 10;

    public function paginate(int $ownerId): LengthAwarePaginator
    {
        return Favorite::query()
            ->where('user_id', $ownerId)
            ->whereHas('ad')
            ->with($this->adRelations())
            ->latest()
            ->paginate(self::PER_PAGE);
    }

    private function adRelations(): array
    {
        return array_merge(
            ['ad'],
            array_map(static fn (string $relation): string => 'ad.'.$relation, Ad::$defaultRelations)
        );
    }
}
