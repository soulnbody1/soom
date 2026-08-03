<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\BidBlockingReason;
use App\Domain\Auction\Enums\NextActionCode;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionSettlement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class NextActionResolver
{
    public function resolve(
        Auction $auction,
        ?User $viewer,
        BidBlockingReason $blockingReason,
        ?AuctionSettlement $settlement,
        bool $hasOpenDispute
    ): array {
        if ($viewer === null) {
            return [NextActionCode::Login, true];
        }

        if ((int) $auction->seller_id === (int) $viewer->id) {
            return $this->sellerAction($auction, $viewer, $settlement, $hasOpenDispute);
        }

        $isWinner = $settlement !== null && (int) $settlement->winner_id === (int) $viewer->id;

        if ($isWinner) {
            return $this->winnerAction($auction, $viewer, $settlement, $hasOpenDispute);
        }

        return $this->bidderAction($auction, $viewer, $blockingReason);
    }

    private function sellerAction(Auction $auction, User $viewer, ?AuctionSettlement $settlement, bool $hasOpenDispute): array
    {
        if ($auction->status === AuctionStatus::AwaitingSellerDeposit) {
            return [NextActionCode::SubmitSellerDeposit, true];
        }

        if ($hasOpenDispute) {
            return [NextActionCode::ContactSupport, true];
        }

        if ($auction->status === AuctionStatus::HandoverPending
            && $settlement?->seller_handover_confirmed_at === null) {
            return [NextActionCode::ConfirmHandover, Gate::forUser($viewer)->allows('confirmSellerHandover', $auction)];
        }

        return [NextActionCode::None, false];
    }

    private function winnerAction(Auction $auction, User $viewer, ?AuctionSettlement $settlement, bool $hasOpenDispute): array
    {
        if ($hasOpenDispute) {
            return [NextActionCode::ContactSupport, true];
        }

        if ($settlement?->status === SettlementStatus::PaymentPending) {
            return [NextActionCode::PaySettlement, true];
        }

        if ($auction->status === AuctionStatus::HandoverPending) {
            return $settlement?->seller_handover_confirmed_at === null
                ? [NextActionCode::AwaitResult, false]
                : [NextActionCode::ConfirmReceipt, Gate::forUser($viewer)->allows('confirmWinnerReceipt', $auction)];
        }

        return [NextActionCode::None, false];
    }

    private function bidderAction(Auction $auction, User $viewer, BidBlockingReason $blockingReason): array
    {
        return match ($blockingReason) {
            BidBlockingReason::None => [NextActionCode::PlaceBid, Gate::forUser($viewer)->allows('bid', $auction)],
            BidBlockingReason::NotRegistered => [NextActionCode::Register, Gate::forUser($viewer)->allows('register', $auction)],
            BidBlockingReason::TermsRequired => [NextActionCode::AcceptTerms, true],
            BidBlockingReason::DepositRequired, BidBlockingReason::DepositRejected => [NextActionCode::SubmitBidderDeposit, true],
            BidBlockingReason::DepositUnderReview => [NextActionCode::AwaitDepositReview, false],
            BidBlockingReason::AuctionNotLive => [NextActionCode::Register, Gate::forUser($viewer)->allows('register', $auction)],
            BidBlockingReason::AuctionEnded => [NextActionCode::AwaitResult, false],
            BidBlockingReason::ParticipantBlocked, BidBlockingReason::ConfigurationUnavailable => [NextActionCode::ContactSupport, true],
            default => [NextActionCode::None, false],
        };
    }
}
