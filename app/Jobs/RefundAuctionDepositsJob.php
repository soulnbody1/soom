<?php

namespace App\Jobs;

use App\Models\Auction;
use App\Models\AuctionBid;
use App\Services\AuctionDepositService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefundAuctionDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $auctionId,
        protected ?int $winnerBidId = null
    ) {}

    public function handle(AuctionDepositService $depositService): void
    {
        Log::info('Starting RefundAuctionDepositsJob', [
            'auction_id' => $this->auctionId,
            'winner_bid_id' => $this->winnerBidId
        ]);

        try {
            if ($this->winnerBidId) {
                $depositService->refundLosersDeposits($this->auctionId, $this->winnerBidId);
                
                $winnerBid = AuctionBid::find($this->winnerBidId);
                if ($winnerBid) {
                    $depositService->applyWinnerDepositToPayment($winnerBid);
                }
            } else {
                $depositService->refundAllBiddersDeposits($this->auctionId);
            }

            Log::info('RefundAuctionDepositsJob completed', [
                'auction_id' => $this->auctionId
            ]);
        } catch (\Exception $e) {
            Log::error('RefundAuctionDepositsJob failed', [
                'auction_id' => $this->auctionId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
