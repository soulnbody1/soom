<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionConfigurationSnapshot;
use App\Repositories\Auction\AuctionConfigurationSnapshotRepository;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ReviewAuctionAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionConfigurationSnapshotRepository $snapshots,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
    ) {}

    public function approve(Auction $auction, ?int $actorId, string $reason, string $actorType = 'admin'): Auction
    {
        return $this->transaction->run(
            fn (): Auction => $this->approveLocked($auction->id, $actorId, $reason, $actorType)
        );
    }

    public function approveLocked(int $auctionId, ?int $actorId, string $reason, string $actorType = 'admin'): Auction
    {
        $auction = $this->auctions->lockForStateChange($auctionId);
        $createdSnapshot = $this->snapshots->createForApprovedAuction($auction, $actorId);
        if ($createdSnapshot->wasRecentlyCreated) {
            $this->audit->log('auction_configuration_snapshot_created', $auction, $actorId, $actorType, [
                'source_version_id' => $createdSnapshot->source_configuration_version_id,
                'snapshot_id' => $createdSnapshot->id,
                'snapshot_hash' => $createdSnapshot->snapshot_hash,
            ]);
        }
        $snapshot = $this->snapshotReader->forAuction($auction);
        $this->createSellerDepositObligation($auction, $snapshot);

        $targetStatus = (int) $snapshot->seller_deposit_required_minor > 0
            ? AuctionStatus::AwaitingSellerDeposit
            : AuctionStatus::Scheduled;

        if ($targetStatus === AuctionStatus::AwaitingSellerDeposit && $auction->seller_deposit_due_at === null) {
            $auction->forceFill([
                'seller_deposit_due_at' => Carbon::now()->addMinutes($snapshot->sellerDepositDeadlineMinutes()),
            ]);
            $this->auctions->save($auction);
        }

        return $this->stateMachine->transition(
            $auction,
            $targetStatus,
            $actorId,
            $actorType,
            $reason
        );
    }

    private function createSellerDepositObligation(Auction $auction, AuctionConfigurationSnapshot $snapshot): void
    {
        if ((int) $snapshot->seller_deposit_required_minor <= 0) {
            return;
        }

        $this->deposits->firstOrCreateDeposit(
            ['auction_id' => $auction->id, 'user_id' => $auction->seller_id, 'type' => 'seller'],
            [
                'status' => AuctionDepositStatus::PendingSubmission,
                'required_amount_minor' => (int) $snapshot->seller_deposit_required_minor,
                'currency_code' => $snapshot->currency_code,
            ]
        );
    }

    public function reject(Auction $auction, ?int $actorId, string $reason, string $actorType = 'admin'): Auction
    {
        if (trim($reason) === '') {
            throw AuctionException::domain('rejection_reason_required');
        }

        return $this->transaction->run(
            fn (): Auction => $this->rejectLocked($auction->id, $actorId, $reason, $actorType)
        );
    }

    public function rejectLocked(int $auctionId, ?int $actorId, string $reason, string $actorType = 'admin'): Auction
    {
        if (trim($reason) === '') {
            throw AuctionException::domain('rejection_reason_required');
        }

        $auction = $this->auctions->lockForStateChange($auctionId);

        return $this->stateMachine->transition($auction, AuctionStatus::Rejected, $actorId, $actorType, $reason);
    }
}
