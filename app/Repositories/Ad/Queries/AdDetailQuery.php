<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;

final class AdDetailQuery
{
    public function findOrFail(int $adId, ?object $viewer): Ad
    {
        return Ad::query()
            ->with(Ad::$defaultRelations)
            ->withCount('views')
            ->withIsFavorite($viewer)
            ->findOrFail($adId);
    }
}
