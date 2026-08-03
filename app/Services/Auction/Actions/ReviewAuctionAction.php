<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
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

    public function approve(Auction $auction, int $adminId, string $reason): Auction
    {
        return $this->transaction->run(function () use ($auction, $adminId, $reason): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $createdSnapshot = $this->snapshots->createForApprovedAuction($auction, $adminId);
            if ($createdSnapshot->wasRecentlyCreated) {
                $this->audit->log('auction_configuration_snapshot_created', $auction, $adminId, 'admin', [
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
                $adminId,
                'admin',
                $reason
            );
        });
    }

    private function createSellerDepositObligation(Auction $auction, \App\Models\Auction\AuctionConfigurationSnapshot $snapshot): void
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

    public function reject(Auction $auction, int $adminId, string $reason): Auction
    {
        if (trim($reason) === '') {
            throw AuctionException::domain('rejection_reason_required');
        }

        return $this->transaction->run(function () use ($auction, $adminId, $reason): Auction {
            $auction = $this->auctions->lockForStateChange($auction->id);

            return $this->stateMachine->transition($auction, AuctionStatus::Rejected, $adminId, 'admin', $reason);
        });
    }
}
