<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionDisputeRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ConfirmAuctionHandoverBySellerAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionDisputeRepository $disputes,
    ) {}

    public function execute(Auction $auction, int $sellerId): Auction
    {
        return $this->transaction->run(function () use ($auction, $sellerId): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $settlement = $this->settlements->lockSettlement($auction->id);

            if ($auction->seller_id !== $sellerId || $auction->status !== AuctionStatus::HandoverPending) {
                throw AuctionException::domain('auction_not_found');
            }

            if (! in_array($settlement->status, [SettlementStatus::Paid, SettlementStatus::HandoverPending], true)) {
                throw AuctionException::domain('settlement_must_be_paid');
            }

            if ($settlement->seller_handover_confirmed_at !== null) {
                throw AuctionException::domain('handover_already_confirmed', [], 409);
            }

            if ($this->disputes->hasOpenDispute($auction->id)) {
                throw AuctionException::domain('handover_blocked_by_dispute');
            }

            $now = Carbon::now();
            $settlement->forceFill([
                'status' => SettlementStatus::HandoverPending,
                'seller_handover_confirmed_at' => $now,
            ]);
            $this->settlements->save($settlement);

            $this->audit->log('auction.seller_handover_confirmed', $auction, $sellerId, 'user', [
                'settlement_public_id' => $settlement->public_id,
            ]);
            $this->audit->outbox('auction.seller_handover_confirmed', $auction, [
                'auction_public_id' => $auction->public_id,
                'settlement_public_id' => $settlement->public_id,
                'seller_id' => $sellerId,
            ]);

            return $auction->refresh()->load('settlement');
        });
    }
}
