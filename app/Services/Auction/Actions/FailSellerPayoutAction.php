<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\User;
use App\Repositories\Auction\AuctionSellerPayoutRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class FailSellerPayoutAction
{
    private const ALLOWED_FROM = [
        SellerPayoutStatus::Pending,
        SellerPayoutStatus::Processing,
        SellerPayoutStatus::OnHold,
    ];

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionSellerPayoutRepository $payouts,
    ) {}

    public function execute(AuctionSellerPayout $payout, User $admin, SellerPayoutStatus $target, string $reason): AuctionSellerPayout
    {
        if (! in_array($target, [SellerPayoutStatus::Failed, SellerPayoutStatus::ManualReview], true)) {
            throw AuctionException::domain('payout_status_invalid');
        }

        if (trim($reason) === '') {
            throw AuctionException::domain('payout_reason_required');
        }

        return $this->transaction->run(function () use ($payout, $admin, $target, $reason): AuctionSellerPayout {
            $payout = $this->payouts->lockById($payout->id);

            if (! in_array($payout->status, self::ALLOWED_FROM, true)) {
                throw AuctionException::domain('payout_status_invalid');
            }

            $payout->forceFill([
                'status' => $target,
                'failure_reason' => $reason,
                'failed_at' => Carbon::now(),
                'processed_by' => $payout->processed_by ?? $admin->id,
            ]);
            $this->payouts->save($payout);

            $eventType = $target === SellerPayoutStatus::Failed
                ? 'auction.seller_payout_failed'
                : 'auction.seller_payout_manual_review';

            $auction = $payout->auction;
            $this->audit->log($eventType, $auction, $admin->id, 'admin', [
                'payout_public_id' => $payout->public_id,
                'reason' => $reason,
            ]);
            $this->audit->outbox($eventType, $auction, [
                'auction_public_id' => $auction->public_id,
                'payout_public_id' => $payout->public_id,
            ]);

            return $payout;
        });
    }
}
