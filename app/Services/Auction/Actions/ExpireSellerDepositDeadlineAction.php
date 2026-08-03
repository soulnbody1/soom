<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionCancellationTrigger;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\PaymentSubmissionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\DTO\Auction\AuctionCancellationContextDTO;
use App\Models\Auction\Auction;
use App\Models\Auction\PaymentSubmission;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class ExpireSellerDepositDeadlineAction
{
    public const REASON = 'seller_deposit_deadline_expired';

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly CancelAuctionFinanciallyAction $cancel,
    ) {}

    public function execute(Auction $auction): bool
    {
        $claimed = $this->transaction->run(function () use ($auction): bool {
            $auction = $this->auctions->lockForStateChange($auction->id);

            if ($auction->status !== AuctionStatus::AwaitingSellerDeposit) {
                return false;
            }

            if ($auction->seller_deposit_due_at === null || Carbon::now()->lessThanOrEqualTo($auction->seller_deposit_due_at)) {
                return false;
            }

            if ($this->claimIsActive($auction)) {
                return false;
            }

            if ($this->hasSubmissionUnderReview($auction)) {
                return false;
            }

            $auction->forceFill(['seller_deposit_deadline_processed_at' => Carbon::now()]);
            $this->auctions->save($auction);

            return true;
        });

        if (! $claimed) {
            return false;
        }

        try {
            $this->cancel->execute(new AuctionCancellationContextDTO(
                auctionId: $auction->id,
                trigger: AuctionCancellationTrigger::SystemTriggered,
                actorId: null,
                actorType: 'system',
                reasonCode: self::REASON,
                reasonText: self::REASON,
                liability: 'seller_fault',
                requestedAt: Carbon::now(),
            ));
        } catch (AuctionException) {
            $this->releaseClaim($auction);

            return false;
        }

        $this->audit->outbox('auction.seller_deposit_expired', $auction->refresh(), [
            'auction_public_id' => $auction->public_id,
            'seller_deposit_due_at' => $auction->seller_deposit_due_at?->toIso8601String(),
            'reason' => self::REASON,
        ]);

        return true;
    }

    private function claimIsActive(Auction $auction): bool
    {
        $claimedAt = $auction->seller_deposit_deadline_processed_at;

        if ($claimedAt === null) {
            return false;
        }

        $leaseMinutes = max(1, (int) config('auction.deadlines.seller_deposit_expiry_lease_minutes', 15));

        return Carbon::now()->lessThan($claimedAt->addMinutes($leaseMinutes));
    }

    private function releaseClaim(Auction $auction): void
    {
        $this->transaction->run(function () use ($auction): void {
            $locked = $this->auctions->lockForStateChange($auction->id);

            if ($locked->status !== AuctionStatus::AwaitingSellerDeposit) {
                return;
            }

            $locked->forceFill(['seller_deposit_deadline_processed_at' => null]);
            $this->auctions->save($locked);
        });
    }

    private function hasSubmissionUnderReview(Auction $auction): bool
    {
        return PaymentSubmission::where('auction_id', $auction->id)
            ->where('purpose', 'seller_deposit')
            ->where('status', PaymentSubmissionStatus::PendingReview->value)
            ->lockForUpdate()
            ->exists();
    }
}
