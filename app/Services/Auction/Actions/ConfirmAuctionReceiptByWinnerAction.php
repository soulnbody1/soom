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
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ConfirmAuctionReceiptByWinnerAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionDisputeRepository $disputes,
        private readonly PlanNonWinnerDepositRefundsAction $nonWinnerDeposits,
        private readonly ResolveSellerDepositDispositionAction $sellerDepositDisposition,
        private readonly CreateSellerPayoutAction $sellerPayout,
    ) {}

    public function execute(Auction $auction, int $winnerId): Auction
    {
        return $this->transaction->run(function () use ($auction, $winnerId): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $settlement = $this->settlements->lockSettlement($auction->id);

            if ($settlement->winner_id !== $winnerId || $auction->status !== AuctionStatus::HandoverPending) {
                throw AuctionException::domain('auction_not_found');
            }

            if ($settlement->seller_handover_confirmed_at === null) {
                throw AuctionException::domain('seller_handover_required');
            }

            if ($this->disputes->hasOpenDispute($auction->id)) {
                throw AuctionException::domain('handover_blocked_by_dispute');
            }

            $now = Carbon::now();
            $settlement->forceFill([
                'status' => SettlementStatus::Completed,
                'buyer_receipt_confirmed_at' => $settlement->buyer_receipt_confirmed_at ?? $now,
                'handover_completed_at' => $settlement->handover_completed_at ?? $now,
                'completed_at' => $settlement->completed_at ?? $now,
            ]);
            $this->settlements->save($settlement);

            $this->audit->log('auction.winner_receipt_confirmed', $auction, $winnerId, 'user', [
                'settlement_public_id' => $settlement->public_id,
            ]);

            $auction = $this->stateMachine
                ->transition($auction, AuctionStatus::Completed, $winnerId, 'user', __('auction.audit.winner_receipt_confirmed'))
                ->load('settlement');
            $this->nonWinnerDeposits->execute($auction, 'completed', $winnerId, 'user');
            $this->sellerDepositDisposition->execute($auction, 'completed', $winnerId, 'user', __('auction.audit.winner_receipt_confirmed'));
            $this->sellerPayout->execute($auction, $settlement, $winnerId, 'user');

            return $auction->refresh()->load('settlement');
        });
    }
}
