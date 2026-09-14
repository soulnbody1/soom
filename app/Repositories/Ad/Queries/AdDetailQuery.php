<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;

final class AdDetailQuery
{
    public function findOrFail(string $publicId, ?object $viewer): Ad
    {
        return Ad::query()
            ->with(Ad::$defaultRelations)
            ->withCount('views')
            ->withIsFavorite($viewer)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
