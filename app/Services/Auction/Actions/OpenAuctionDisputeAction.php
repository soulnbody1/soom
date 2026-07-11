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

final class OpenAuctionDisputeAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(Auction $auction, int $actorId, string $reason): AuctionDispute
    {
        if (trim($reason) === '') {
            throw new AuctionException(__('auction.errors.dispute_reason_required'));
        }

        return $this->transaction->run(function () use ($auction, $actorId, $reason): AuctionDispute {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $settlement = $auction->settlement()->lockForUpdate()->firstOrFail();

            if ($auction->status !== AuctionStatus::HandoverPending) {
                throw new AuctionException(__('auction.errors.dispute_not_available'));
            }

            $dispute = AuctionDispute::firstOrCreate(
                [
                    'auction_id' => $auction->id,
                    'settlement_id' => $settlement->id,
                    'status' => 'open',
                ],
                [
                    'opened_by' => $actorId,
                    'reason' => $reason,
                    'opened_at' => Carbon::now(),
                ]
            );

            $settlement->forceFill(['status' => SettlementStatus::Disputed])->save();
            $this->stateMachine->transition($auction, AuctionStatus::Disputed, $actorId, 'user', $reason);
            $this->audit->outbox('auction.dispute_opened', $auction, [
                'auction_public_id' => $auction->public_id,
                'dispute_public_id' => $dispute->public_id,
            ]);

            return $dispute->refresh();
        });
    }
}
