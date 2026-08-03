<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionDispute;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use Illuminate\Support\Collection;

final readonly class ViewerAuctionContext
{
    public function __construct(
        public Collection $participants,
        public Collection $acceptedTermsVersions,
        public Collection $bidderDeposits,
        public Collection $sellerDeposits,
        public Collection $bidSummaries,
        public Collection $settlements,
        public Collection $openDisputes,
    ) {}

    public function participant(int $auctionId): ?AuctionParticipant
    {
        return $this->participants->get($auctionId);
    }

    public function hasAcceptedTerms(int $auctionId, ?int $termsVersionId): bool
    {
        if ($termsVersionId === null) {
            return false;
        }

        return (bool) $this->acceptedTermsVersions
            ->get($auctionId, collect())
            ->firstWhere('terms_version_id', $termsVersionId);
    }

    public function termsAcceptedAt(int $auctionId, ?int $termsVersionId): ?string
    {
        if ($termsVersionId === null) {
            return null;
        }

        $acceptance = $this->acceptedTermsVersions
            ->get($auctionId, collect())
            ->firstWhere('terms_version_id', $termsVersionId);

        return $acceptance?->accepted_at?->toIso8601String();
    }

    public function bidderDeposit(int $auctionId): ?AuctionDeposit
    {
        return $this->bidderDeposits->get($auctionId);
    }

    public function sellerDeposit(int $auctionId): ?AuctionDeposit
    {
        return $this->sellerDeposits->get($auctionId);
    }

    public function highestBidMinor(int $auctionId): ?int
    {
        $summary = $this->bidSummaries->get($auctionId);

        return $summary === null ? null : (int) $summary->my_max_minor;
    }

    public function bidCount(int $auctionId): int
    {
        $summary = $this->bidSummaries->get($auctionId);

        return $summary === null ? 0 : (int) $summary->my_bids_count;
    }

    public function settlement(int $auctionId): ?AuctionSettlement
    {
        return $this->settlements->get($auctionId);
    }

    public function openDispute(int $auctionId): ?AuctionDispute
    {
        return $this->openDisputes->get($auctionId);
    }
}
