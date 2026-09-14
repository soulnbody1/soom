<?php

declare(strict_types=1);

namespace App\Repositories\Ad\Queries;

use App\Models\Ad;
use Illuminate\Support\Facades\Auth;

final class TrashedAdQuery
{
    public function ownedActiveOrFail(string $publicId): Ad
    {
        return Ad::query()
            ->where('public_id', $publicId)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    public function ownedOrFail(string $publicId): Ad
    {
        return Ad::withTrashed()
            ->where('public_id', $publicId)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    public function findOrFail(string $publicId): Ad
    {
        return Ad::withTrashed()->where('public_id', $publicId)->firstOrFail();
    }
}
