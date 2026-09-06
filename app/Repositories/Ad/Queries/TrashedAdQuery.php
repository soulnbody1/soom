<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;
use Illuminate\Support\Facades\Auth;

final class TrashedAdQuery
{
    public function ownedOrFail(int $adId): Ad
    {
        return Ad::withTrashed()
            ->where('id', $adId)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    public function findOrFail(int $adId): Ad
    {
        return Ad::withTrashed()->where('id', $adId)->firstOrFail();
    }
}
