<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDispute;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ResolveAuctionDisputeAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(Auction $auction, AuctionDispute $dispute, int $adminId, string $resolution, string $note): Auction
    {
        if (trim($note) === '') {
            throw new AuctionException(__('auction.errors.dispute_resolution_note_required'));
        }

        return $this->transaction->run(function () use ($auction, $dispute, $adminId, $resolution, $note): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $dispute = AuctionDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            $settlement = $auction->settlement()->lockForUpdate()->firstOrFail();

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
            ])->save();

            $dispute->forceFill([
                'status' => 'resolved',
                'resolved_by' => $adminId,
                'resolution_note' => $note,
                'resolved_at' => $now,
            ])->save();

            $this->audit->log('auction.dispute_resolved', $auction, $adminId, 'admin', [
                'dispute_public_id' => $dispute->public_id,
                'resolution' => $resolution,
            ]);

            return $this->stateMachine->transition($auction, $target, $adminId, 'admin', $note)->load('settlement');
        });
    }
}
