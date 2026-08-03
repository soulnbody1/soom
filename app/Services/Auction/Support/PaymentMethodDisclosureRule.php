<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentPurpose;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionSettlement;
use App\Models\User;

final class PaymentMethodDisclosureRule
{
    private const SETTLED_DEPOSIT_STATUSES = [
        AuctionDepositStatus::Held,
        AuctionDepositStatus::AppliedToSettlement,
        AuctionDepositStatus::RefundPending,
        AuctionDepositStatus::Refunded,
        AuctionDepositStatus::Forfeited,
    ];

    public function assertCanSee(Auction $auction, User $user, PaymentPurpose $purpose): void
    {
        match ($purpose) {
            PaymentPurpose::SellerDeposit => $this->assertSellerObligation($auction, $user),
            PaymentPurpose::BidderDeposit => $this->assertBidderObligation($auction, $user),
            PaymentPurpose::WinnerSettlement => $this->assertWinnerObligation($auction, $user),
        };
    }

    private function assertSellerObligation(Auction $auction, User $user): void
    {
        if ((int) $auction->seller_id !== (int) $user->id) {
            throw AuctionException::domain('payment_target_owner_mismatch', [], 403);
        }

        if ($auction->status !== AuctionStatus::AwaitingSellerDeposit) {
            throw AuctionException::domain('seller_deposit_state_not_allowed', [], 403);
        }
    }

    private function assertBidderObligation(Auction $auction, User $user): void
    {
        if ((int) $auction->seller_id === (int) $user->id) {
            throw AuctionException::domain('payment_target_owner_mismatch', [], 403);
        }

        if (! in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
            throw AuctionException::domain('bidder_deposit_state_not_allowed', [], 403);
        }

        $participant = AuctionParticipant::where('auction_id', $auction->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $participant) {
            throw AuctionException::domain('registration_required', [], 403);
        }

        $deposit = AuctionDeposit::where('auction_id', $auction->id)
            ->where('user_id', $user->id)
            ->where('type', 'bidder')
            ->first();

        if ($deposit && in_array($deposit->status, self::SETTLED_DEPOSIT_STATUSES, true)) {
            throw AuctionException::domain('payment_obligation_already_paid', [], 403);
        }
    }

    private function assertWinnerObligation(Auction $auction, User $user): void
    {
        $settlement = AuctionSettlement::where('auction_id', $auction->id)
            ->where('current_marker', 1)
            ->first();

        if (! $settlement || (int) $settlement->winner_id !== (int) $user->id) {
            throw AuctionException::domain('payment_target_owner_mismatch', [], 403);
        }

        if ($settlement->status !== SettlementStatus::PaymentPending || (int) $settlement->remaining_amount_minor <= 0) {
            throw AuctionException::domain('payment_obligation_already_paid', [], 403);
        }
    }
}
