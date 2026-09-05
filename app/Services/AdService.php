<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ad;
use Illuminate\Support\Facades\Auth;

class AdService
{
    public function getTrashedAdForUser(int $id): Ad
    {
        return Ad::withTrashed()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    public function getTrashedAd(int $id): Ad
    {
        return Ad::withTrashed()->where('id', $id)->firstOrFail();
    }
}
