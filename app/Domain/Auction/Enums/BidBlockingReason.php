<?php

declare(strict_types=1);

namespace App\Domain\Auction\Enums;

enum BidBlockingReason: string
{
    case None = 'none';
    case AuthenticationRequired = 'authentication_required';
    case IsSeller = 'is_seller';
    case AuctionNotLive = 'auction_not_live';
    case BiddingWindowClosed = 'bidding_window_closed';
    case NotRegistered = 'not_registered';
    case ParticipantBlocked = 'blocked';
    case NotQualified = 'not_qualified';
    case TermsRequired = 'terms_required';
    case DepositRequired = 'deposit_required';
    case DepositUnderReview = 'deposit_under_review';
    case DepositRejected = 'deposit_rejected';
    case AuctionEnded = 'auction_ended';
    case PaymentRequired = 'payment_required';
    case HandoverRequired = 'handover_required';
    case ConfigurationUnavailable = 'configuration_unavailable';

    public function errorKey(): string
    {
        return match ($this) {
            self::None => 'bid_below_minimum',
            self::AuthenticationRequired => 'unauthenticated',
            self::IsSeller => 'seller_cannot_bid',
            self::AuctionNotLive, self::AuctionEnded => 'auction_not_live',
            self::BiddingWindowClosed => 'bidding_window_closed',
            self::NotRegistered, self::ParticipantBlocked, self::NotQualified => 'bidder_not_qualified',
            self::TermsRequired => 'terms_required_before_bidding',
            self::DepositRequired, self::DepositUnderReview, self::DepositRejected => 'bidder_deposit_required',
            self::PaymentRequired, self::HandoverRequired => 'winner_payment_state_not_allowed',
            self::ConfigurationUnavailable => 'configuration_snapshot_missing',
        };
    }

    public function blocksBidding(): bool
    {
        return $this !== self::None;
    }
}
