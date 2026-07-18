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
use App\Services\Auction\Support\SellerPayoutRules;
use Illuminate\Support\Carbon;

final class StartSellerPayoutProcessingAction
{
    private const ALLOWED_FROM = [
        SellerPayoutStatus::Pending,
        SellerPayoutStatus::Failed,
        SellerPayoutStatus::ManualReview,
    ];

    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionSellerPayoutRepository $payouts,
        private readonly SellerPayoutRules $rules,
    ) {}

    public function execute(AuctionSellerPayout $payout, User $admin): AuctionSellerPayout
    {
        return $this->transaction->run(function () use ($payout, $admin): AuctionSellerPayout {
            $payout = $this->payouts->lockById($payout->id);

            if (! in_array($payout->status, self::ALLOWED_FROM, true)) {
                throw new AuctionException(__('auction.errors.payout_status_invalid'));
            }

            $this->rules->ensureNoOpenDispute($payout);
            $this->rules->ensureDestinationSnapshot($payout);

            $payout->forceFill([
                'status' => SellerPayoutStatus::Processing,
                'processing_started_at' => Carbon::now(),
                'processed_by' => $admin->id,
            ]);
            $this->payouts->save($payout);

            $auction = $payout->auction;
            $this->audit->log('auction.seller_payout_processing', $auction, $admin->id, 'admin', [
                'payout_public_id' => $payout->public_id,
            ]);
            $this->audit->outbox('auction.seller_payout_processing', $auction, [
                'auction_public_id' => $auction->public_id,
                'payout_public_id' => $payout->public_id,
            ]);

            return $payout;
        });
    }
}
