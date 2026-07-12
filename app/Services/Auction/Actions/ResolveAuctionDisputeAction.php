<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDispute;
use App\Repositories\Auction\AuctionDisputeRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ResolveAuctionDisputeAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionDisputeRepository $disputes,
        private readonly ResolveSellerDepositDispositionAction $sellerDepositDisposition,
    ) {}

    public function execute(
        Auction $auction,
        AuctionDispute $dispute,
        int $adminId,
        string $resolution,
        string $note,
        ?string $sellerDepositDisposition = null,
        ?int $sellerDepositForfeitAmountMinor = null,
    ): Auction {
        if (trim($note) === '') {
            throw new AuctionException(__('auction.errors.dispute_resolution_note_required'));
        }

        return $this->transaction->run(function () use ($auction, $dispute, $adminId, $resolution, $note, $sellerDepositDisposition, $sellerDepositForfeitAmountMinor): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $dispute = $this->disputes->lockForResolution($dispute->id);
            $settlement = $this->settlements->lockSettlement($auction->id);

            if ($dispute->auction_id !== $auction->id || $dispute->status !== 'open') {
                throw new AuctionException(__('auction.errors.dispute_not_available'));
            }

            $target = match ($resolution) {
                'complete' => AuctionStatus::Completed,
                'resume_handover' => AuctionStatus::HandoverPending,
                'cancel' => AuctionStatus::Cancelled,
                default => throw new AuctionException(__('auction.errors.dispute_resolution_invalid')),
            };

            $settlementStatus = match ($target) {
                AuctionStatus::Completed => SettlementStatus::Completed,
                AuctionStatus::HandoverPending => SettlementStatus::HandoverPending,
                AuctionStatus::Cancelled => SettlementStatus::Cancelled,
                default => $settlement->status,
            };

            $now = Carbon::now();
            $settlement->forceFill([
                'status' => $settlementStatus,
                'completed_at' => $target === AuctionStatus::Completed ? ($settlement->completed_at ?? $now) : $settlement->completed_at,
            ]);
            $this->settlements->save($settlement);

            $dispute->forceFill([
                'status' => 'resolved',
                'resolved_by' => $adminId,
                'resolution_note' => $note,
                'resolved_at' => $now,
            ]);
            $this->disputes->save($dispute);

            $this->audit->log('auction.dispute_resolved', $auction, $adminId, 'admin', [
                'dispute_public_id' => $dispute->public_id,
                'resolution' => $resolution,
            ]);

            $auction = $this->stateMachine->transition($auction, $target, $adminId, 'admin', $note)->load('settlement');
            $this->sellerDepositDisposition->execute($auction, 'dispute_resolution', $adminId, 'admin', $note, [
                'resolution' => $resolution,
                'seller_deposit_disposition' => $sellerDepositDisposition,
                'forfeit_amount_minor' => $sellerDepositForfeitAmountMinor,
            ]);

            return $auction->refresh()->load('settlement');
        });
    }
}
