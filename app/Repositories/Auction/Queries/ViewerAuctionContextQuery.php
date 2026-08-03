<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\Auction\AuctionTermsAcceptance;
use Illuminate\Support\Collection;

final class ViewerAuctionContextQuery
{
    public function load(array $auctionIds, ?int $viewerId): ViewerAuctionContext
    {
        $auctionIds = array_values(array_unique(array_map('intval', $auctionIds)));

        if ($auctionIds === [] || $viewerId === null) {
            return new ViewerAuctionContext(
                participants: collect(),
                acceptedTermsVersions: collect(),
                bidderDeposits: collect(),
                sellerDeposits: collect(),
                bidSummaries: collect(),
                settlements: $this->settlements($auctionIds),
                openDisputes: $this->openDisputes($auctionIds),
            );
        }

        $deposits = AuctionDeposit::whereIn('auction_id', $auctionIds)
            ->where('user_id', $viewerId)
            ->get()
            ->groupBy('auction_id');

        return new ViewerAuctionContext(
            participants: AuctionParticipant::whereIn('auction_id', $auctionIds)
                ->where('user_id', $viewerId)
                ->get()
                ->keyBy('auction_id'),
            acceptedTermsVersions: AuctionTermsAcceptance::whereIn('auction_id', $auctionIds)
                ->where('user_id', $viewerId)
                ->get(['auction_id', 'terms_version_id', 'accepted_at'])
                ->groupBy('auction_id'),
            bidderDeposits: $deposits->map(
                fn (Collection $rows) => $rows->firstWhere('type', 'bidder')
            )->filter(),
            sellerDeposits: $deposits->map(
                fn (Collection $rows) => $rows->firstWhere('type', 'seller')
            )->filter(),
            bidSummaries: AuctionBid::whereIn('auction_id', $auctionIds)
                ->where('bidder_id', $viewerId)
                ->selectRaw('auction_id, max(amount_minor) as my_max_minor, count(*) as my_bids_count')
                ->groupBy('auction_id')
                ->get()
                ->keyBy('auction_id'),
            settlements: $this->settlements($auctionIds),
            openDisputes: $this->openDisputes($auctionIds),
        );
    }

    private function settlements(array $auctionIds): Collection
    {
        if ($auctionIds === []) {
            return collect();
        }

        return AuctionSettlement::whereIn('auction_id', $auctionIds)
            ->where('current_marker', 1)
            ->get()
            ->keyBy('auction_id');
    }

    private function openDisputes(array $auctionIds): Collection
    {
        if ($auctionIds === []) {
            return collect();
        }

        return AuctionDispute::whereIn('auction_id', $auctionIds)
            ->where('status', 'open')
            ->get()
            ->keyBy('auction_id');
    }
}
