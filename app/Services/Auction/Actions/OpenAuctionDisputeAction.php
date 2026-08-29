<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionDispute;
use App\Repositories\Auction\AuctionDisputeRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionSettlementRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class OpenAuctionDisputeAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionSettlementRepository $settlements,
        private readonly AuctionDisputeRepository $disputes,
    ) {}

    public function execute(Auction $auction, int $actorId, string $reason): AuctionDispute
    {
        if (trim($reason) === '') {
            throw AuctionException::domain('dispute_reason_required');
        }

        return $this->transaction->run(function () use ($auction, $actorId, $reason): AuctionDispute {
            $auction = $this->auctions->lockForStateChange($auction->id);
            $settlement = $this->settlements->lockSettlement($auction->id);

            if ($auction->status !== AuctionStatus::HandoverPending) {
                throw AuctionException::domain('dispute_not_available');
            }

            if ($this->disputes->hasOpenDispute($auction->id)) {
                throw AuctionException::domain('dispute_already_open', [], 409);
            }

            $dispute = $this->disputes->firstOrCreateOpenDispute(
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

            $this->audit->log('auction.dispute_opened', $auction, $actorId, 'user', [
                'dispute_public_id' => $dispute->public_id,
            ]);
            $this->audit->outbox('auction.dispute_opened', $auction, [
                'auction_public_id' => $auction->public_id,
                'dispute_public_id' => $dispute->public_id,
            ]);

            return $dispute->refresh();
        });
    }
}
