<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ConfirmAuctionHandoverBySellerAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(Auction $auction, int $sellerId): Auction
    {
        return $this->transaction->run(function () use ($auction, $sellerId): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $settlement = $auction->settlement()->lockForUpdate()->firstOrFail();

            if ($auction->seller_id !== $sellerId || $auction->status !== AuctionStatus::HandoverPending) {
                throw new AuctionException(__('auction.errors.auction_not_found'));
            }

            if (! in_array($settlement->status, [SettlementStatus::Paid, SettlementStatus::HandoverPending], true)) {
                throw new AuctionException(__('auction.errors.settlement_must_be_paid'));
            }

            $now = Carbon::now();
            $settlement->forceFill([
                'status' => SettlementStatus::HandoverPending,
                'seller_handover_confirmed_at' => $settlement->seller_handover_confirmed_at ?? $now,
            ])->save();

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
