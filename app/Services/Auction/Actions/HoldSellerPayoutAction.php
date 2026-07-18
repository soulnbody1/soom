<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\SellerPayoutStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\User;
use App\Repositories\Auction\AuctionDisputeRepository;
use App\Repositories\Auction\AuctionSellerPayoutRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class HoldSellerPayoutAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionSellerPayoutRepository $payouts,
        private readonly AuctionDisputeRepository $disputes,
    ) {}

    public function hold(AuctionSellerPayout $payout, User $admin, string $reason): AuctionSellerPayout
    {
        if (trim($reason) === '') {
            throw new AuctionException(__('auction.errors.payout_reason_required'));
        }

        return $this->transaction->run(function () use ($payout, $admin, $reason): AuctionSellerPayout {
            $payout = $this->payouts->lockById($payout->id);

            if (! in_array($payout->status, [SellerPayoutStatus::Pending, SellerPayoutStatus::Processing], true)) {
                throw new AuctionException(__('auction.errors.payout_status_invalid'));
            }

            $payout->forceFill([
                'status' => SellerPayoutStatus::OnHold,
                'hold_reason' => $reason,
                'held_at' => Carbon::now(),
            ]);
            $this->payouts->save($payout);

            $auction = $payout->auction;
            $this->audit->log('auction.seller_payout_on_hold', $auction, $admin->id, 'admin', [
                'payout_public_id' => $payout->public_id,
                'reason' => $reason,
            ]);
            $this->audit->outbox('auction.seller_payout_on_hold', $auction, [
                'auction_public_id' => $auction->public_id,
                'payout_public_id' => $payout->public_id,
            ]);

            return $payout;
        });
    }

    public function release(AuctionSellerPayout $payout, User $admin): AuctionSellerPayout
    {
        return $this->transaction->run(function () use ($payout, $admin): AuctionSellerPayout {
            $payout = $this->payouts->lockById($payout->id);

            if ($payout->status !== SellerPayoutStatus::OnHold) {
                throw new AuctionException(__('auction.errors.payout_status_invalid'));
            }

            if ($this->disputes->hasOpenDispute((int) $payout->auction_id)) {
                throw new AuctionException(__('auction.errors.payout_blocked_by_dispute'));
            }

            $payout->forceFill([
                'status' => SellerPayoutStatus::Pending,
                'hold_reason' => null,
                'held_at' => null,
            ]);
            $this->payouts->save($payout);

            $this->audit->log('auction.seller_payout_hold_released', $payout->auction, $admin->id, 'admin', [
                'payout_public_id' => $payout->public_id,
            ]);

            return $payout;
        });
    }
}
