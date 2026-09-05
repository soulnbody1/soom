<?php

namespace App\Services;

use App\Models\AdReelView;
use Illuminate\Support\Facades\Auth;

class AdReelViewService
{
    public function store(int $adReelId): AdReelView
    {
        return AdReelView::firstOrCreate(
            ['ad_reel_id' => $adReelId, 'user_id' => Auth::id()],
            ['viewed_at' => now()],
        );
    }
}
