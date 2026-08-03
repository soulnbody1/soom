<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\BidBlockingReason;
use App\DTO\Auction\BidEligibilityContextDTO;

final class BidEligibilityLadder
{
    public function evaluate(BidEligibilityContextDTO $context): BidBlockingReason
    {
        $auctionGate = $this->evaluateAuctionGate($context);

        if ($auctionGate->blocksBidding()) {
            return $auctionGate;
        }

        return $this->evaluateParticipantGate($context);
    }

    public function evaluateAuctionGate(BidEligibilityContextDTO $context): BidBlockingReason
    {
        if ($context->viewerId === null) {
            return BidBlockingReason::AuthenticationRequired;
        }

        if ($context->sellerId === $context->viewerId) {
            return BidBlockingReason::IsSeller;
        }

        if (! $context->configurationAvailable) {
            return BidBlockingReason::ConfigurationUnavailable;
        }

        if ($this->hasEnded($context->status)) {
            return BidBlockingReason::AuctionEnded;
        }

        if ($context->status !== AuctionStatus::Live || ! $context->startsAt || ! $context->endsAt) {
            return BidBlockingReason::AuctionNotLive;
        }

        if ($context->startsAt->greaterThan($context->now) || ! $context->now->lessThan($context->endsAt)) {
            return BidBlockingReason::BiddingWindowClosed;
        }

        return BidBlockingReason::None;
    }

    public function evaluateParticipantGate(BidEligibilityContextDTO $context): BidBlockingReason
    {
        if ($context->participantStatus === null) {
            return BidBlockingReason::NotRegistered;
        }

        if ($context->participantStatus === AuctionParticipantStatus::Blocked) {
            return BidBlockingReason::ParticipantBlocked;
        }

        if (! $context->hasAcceptedTerms || $context->requiredTermsVersionId === null) {
            return BidBlockingReason::TermsRequired;
        }

        if ($context->participantStatus !== AuctionParticipantStatus::Qualified) {
            return $this->depositReason($context);
        }

        if ($context->depositRequiredMinor > 0
            && ($context->depositStatus !== AuctionDepositStatus::Held
                || $context->depositHeldMinor < $context->depositRequiredMinor)) {
            return $this->depositReason($context);
        }

        return BidBlockingReason::None;
    }

    private function depositReason(BidEligibilityContextDTO $context): BidBlockingReason
    {
        if ($context->depositRequiredMinor <= 0) {
            return BidBlockingReason::NotQualified;
        }

        return match ($context->depositStatus) {
            AuctionDepositStatus::PendingReview => BidBlockingReason::DepositUnderReview,
            AuctionDepositStatus::Rejected => BidBlockingReason::DepositRejected,
            default => BidBlockingReason::DepositRequired,
        };
    }

    private function hasEnded(AuctionStatus $status): bool
    {
        return in_array($status, [
            AuctionStatus::Ended,
            AuctionStatus::SettlementPending,
            AuctionStatus::PaymentPending,
            AuctionStatus::HandoverPending,
            AuctionStatus::Completed,
            AuctionStatus::Unsold,
            AuctionStatus::Defaulted,
            AuctionStatus::Disputed,
        ], true);
    }
}
