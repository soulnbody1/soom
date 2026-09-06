<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\Models\AdReelView;
use Illuminate\Support\Facades\Auth;

final class RecordAdReelViewAction
{
    public function execute(int $adReelId): AdReelView
    {
        return AdReelView::firstOrCreate(
            ['ad_reel_id' => $adReelId, 'user_id' => Auth::id()],
            ['viewed_at' => now()],
        );
    }
}
