<?php

declare(strict_types=1);

namespace App\Application\Auction\Services;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMetric;
use App\Models\Auction\AuctionView;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AuctionMetricsRecorder
{
    public function ensure(Auction $auction): AuctionMetric
    {
        return AuctionMetric::firstOrCreate(['auction_id' => $auction->id]);
    }

    public function recordView(Auction $auction, ?int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        $viewerHash = hash('sha256', implode('|', [
            $auction->id,
            $userId ?: 'guest',
            $ipAddress ?: 'unknown',
            substr($userAgent ?: 'unknown', 0, 160),
            config('app.key'),
        ]));

        $this->ensure($auction)->increment('views_count');

        try {
            AuctionView::create([
                'auction_id' => $auction->id,
                'user_id' => $userId,
                'viewer_hash' => $viewerHash,
                'viewed_at' => Carbon::now(),
            ]);

            AuctionMetric::where('auction_id', $auction->id)->increment('unique_views_count');
        } catch (QueryException) {
            // Unique viewer already counted; total views still increments.
        }
    }

    public function refreshBidMetrics(int $auctionId): void
    {
        $summary = DB::table('auction_bids')
            ->selectRaw('COUNT(*) as bids_count, COUNT(DISTINCT bidder_id) as unique_bidders_count, MAX(accepted_at) as last_bid_at')
            ->where('auction_id', $auctionId)
            ->first();

        AuctionMetric::updateOrCreate(
            ['auction_id' => $auctionId],
            [
                'bids_count' => (int) ($summary->bids_count ?? 0),
                'unique_bidders_count' => (int) ($summary->unique_bidders_count ?? 0),
                'last_bid_at' => $summary->last_bid_at,
            ]
        );
    }

    public function refreshParticipants(int $auctionId): void
    {
        AuctionMetric::updateOrCreate(
            ['auction_id' => $auctionId],
            ['participants_count' => DB::table('auction_participants')->where('auction_id', $auctionId)->count()]
        );
    }
}
