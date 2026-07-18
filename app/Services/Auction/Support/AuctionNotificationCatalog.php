<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

/**
 * personal: database + private user broadcast (+FCM). realtime: public auction
 * channel. public: marketing announcement channel. Events with all flags false
 * are processed without user-facing delivery; uncatalogued events dead-letter.
 */
final class AuctionNotificationCatalog
{
    public const AUCTION_CHANNEL_PREFIX = 'auction.';

    public const PUBLIC_CHANNEL = 'public.auctions';

    public const SCREEN_AUCTION_DETAILS = 'auction_details';

    public const SCREEN_SELLER_AUCTION = 'seller_auction';

    public const SCREEN_AUCTION_PAYMENT = 'auction_payment';

    public const SCREEN_AUCTION_REFUNDS = 'auction_refunds';

    public const SCREEN_AUCTION_DISPUTE = 'auction_dispute';

    public const SCREEN_SELLER_PAYOUT = 'seller_payouts';

    private const EVENTS = [
        'auction.status_changed' => ['personal' => true, 'realtime' => true, 'public' => true],
        'auction.bid_accepted' => ['personal' => true, 'realtime' => true, 'public' => false],
        'auction.participant_registered' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.payment_submitted' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.payment_approved' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.payment_rejected' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.finalized' => ['personal' => true, 'realtime' => true, 'public' => false],
        'auction.winner_defaulted' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.alternative_winner_selected' => ['personal' => true, 'realtime' => true, 'public' => false],
        'auction.winner_deposit_forfeited' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_deposit_forfeited' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_deposit_partially_forfeited' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_deposit_refund_planned' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_deposit_manual_review' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.non_winner_deposit_refund_planned' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.refund_succeeded' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.refund_manual_review' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_handover_confirmed' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.dispute_opened' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.dispute_resolved' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.cancelled' => ['personal' => true, 'realtime' => true, 'public' => true],
        'auction.seller_payout_created' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_payout_on_hold' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_payout_processing' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_payout_paid' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_payout_failed' => ['personal' => true, 'realtime' => false, 'public' => false],
        'auction.seller_payout_manual_review' => ['personal' => true, 'realtime' => false, 'public' => false],

        'auction.no_alternative_winner' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.alternative_settlement_created' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.seller_deposit_held' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.non_winner_deposit_released' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.refund_processing' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.refund_failed' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.refund_cancelled' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.cancellation_started' => ['personal' => false, 'realtime' => false, 'public' => false],
        'auction.cancellation_financial_plan_created' => ['personal' => false, 'realtime' => false, 'public' => false],
    ];

    public static function supports(string $eventType): bool
    {
        return isset(self::EVENTS[$eventType]);
    }

    /**
     * @return array<int, string>
     */
    public static function eventTypes(): array
    {
        return array_keys(self::EVENTS);
    }

    public static function hasPersonal(string $eventType): bool
    {
        return self::EVENTS[$eventType]['personal'] ?? false;
    }

    public static function hasRealtime(string $eventType): bool
    {
        return self::EVENTS[$eventType]['realtime'] ?? false;
    }

    public static function hasPublicAnnouncement(string $eventType): bool
    {
        return self::EVENTS[$eventType]['public'] ?? false;
    }

    public static function auctionChannelName(string $auctionPublicId): string
    {
        return self::AUCTION_CHANNEL_PREFIX.$auctionPublicId;
    }
}
