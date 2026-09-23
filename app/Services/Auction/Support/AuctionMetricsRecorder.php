<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMetric;
use App\Services\Market\MarketQuery;
use Illuminate\Support\Carbon;

final class AuctionMetricsRecorder
{
    public function __construct(private readonly MarketQuery $markets) {}

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

        $now = Carbon::now();
        $isNewViewer = $this->markets->table('auction_views')->insertOrIgnore([
            'market_id' => $auction->market_id,
            'auction_id' => $auction->id,
            'user_id' => $userId,
            'viewer_hash' => $viewerHash,
            'viewed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;

        $counters = ['views_count' => 1];

        if ($isNewViewer) {
            $counters['unique_views_count'] = 1;
        }

        if (AuctionMetric::where('auction_id', $auction->id)->incrementEach($counters) === 0) {
            $this->ensure($auction)->incrementEach($counters);
        }
    }

    public function refreshBidMetrics(int $auctionId): void
    {
        $summary = $this->markets->table('auction_bids')
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
            ['participants_count' => $this->markets->table('auction_participants')->where('auction_id', $auctionId)->count()]
        );
    }
}
