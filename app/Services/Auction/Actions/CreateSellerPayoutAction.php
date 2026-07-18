<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\AuctionSettlement;
use App\Repositories\Auction\AuctionDisputeRepository;
use App\Repositories\Auction\AuctionSellerPayoutRepository;
use App\Repositories\Auction\PayoutDestinationRepository;
use App\Services\Auction\Support\AuctionAudit;

/**
 * Must run inside the caller's transaction with the settlement row locked.
 */
final class CreateSellerPayoutAction
{
    public function __construct(
        private readonly AuctionAudit $audit,
        private readonly AuctionSellerPayoutRepository $payouts,
        private readonly PayoutDestinationRepository $destinations,
        private readonly AuctionDisputeRepository $disputes,
    ) {}

    public function execute(Auction $auction, AuctionSettlement $settlement, ?int $actorId, string $actorType): ?AuctionSellerPayout
    {
        if ($settlement->status !== SettlementStatus::Completed) {
            return null;
        }

        $existing = $this->payouts->findBySettlementId($settlement->id);
        if ($existing !== null) {
            return $existing;
        }

        $netMinor = (int) $settlement->seller_net_amount_minor;
        if ($netMinor <= 0) {
            return null;
        }

        $sellerId = (int) $auction->seller_id;
        $destination = $this->destinations->defaultFor($sellerId);
        $hasOpenDispute = $this->disputes->hasOpenDispute($auction->id);

        $payout = $this->payouts->create([
            'auction_id' => $auction->id,
            'settlement_id' => $settlement->id,
            'seller_id' => $sellerId,
            'status' => $hasOpenDispute ? SellerPayoutStatus::OnHold->value : SellerPayoutStatus::Pending->value,
            'winning_amount_minor' => (int) $settlement->winning_amount_minor,
            'platform_fee_minor' => (int) $settlement->platform_fee_minor,
            'amount_minor' => $netMinor,
            'currency_code' => (string) $settlement->currency_code,
            'destination_id' => $destination?->id,
            'recipient_name' => $destination?->recipient_name,
            'identifier_type' => $destination?->identifier_type,
            'identifier_value' => $destination?->identifier_value,
            'hold_reason' => $hasOpenDispute ? 'dispute' : null,
            'held_at' => $hasOpenDispute ? now() : null,
        ]);

        $this->audit->log('auction.seller_payout_created', $auction, $actorId, $actorType, [
            'payout_public_id' => $payout->public_id,
            'settlement_public_id' => $settlement->public_id,
            'amount_minor' => $netMinor,
            'on_hold' => $hasOpenDispute,
        ]);

        $this->audit->outbox('auction.seller_payout_created', $auction, [
            'auction_public_id' => $auction->public_id,
            'payout_public_id' => $payout->public_id,
            'amount_minor' => $netMinor,
            'currency_code' => $payout->currency_code,
            'has_destination' => $payout->hasDestinationSnapshot(),
            'on_hold' => $hasOpenDispute,
        ]);

        return $payout;
    }
}
