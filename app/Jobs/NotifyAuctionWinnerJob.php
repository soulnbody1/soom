<?php

namespace App\Jobs;

use App\Models\Auction;
use App\Models\AuctionBid;
use App\Notifications\AuctionWonNotification;
use App\Notifications\AuctionEndedForAdvertiserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotifyAuctionWinnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $auctionId) {}

    public function handle(): void
    {
        Log::info('Starting NotifyAuctionWinnerJob', [
            'auction_id' => $this->auctionId
        ]);

        try {
            $auction = Auction::with(['user', 'winner', 'winningBid', 'images'])->find($this->auctionId);

            if (!$auction) {
                Log::warning('Auction not found for notification', [
                    'auction_id' => $this->auctionId
                ]);
                return;
            }

            // إشعار الفائز
            if ($auction->winner) {
                Notification::send(
                    $auction->winner,
                    new AuctionWonNotification($auction)
                );

                if ($auction->winner->fcm_token) {
                    SendFcmNotification::dispatch(
                        $auction->winner->fcm_token,
                        '🎉 مبروك! فزت بالمزاد',
                        "لقد فزت بالمزاد: {$auction->title} بقيمة {$auction->current_bid} د.أ",
                        [
                            'type' => 'auction_won',
                            'auction_id' => (string) $auction->id,
                        ]
                    );
                }
            }

            // إشعار البائع
            $advertiser = $auction->user;
            if ($advertiser) {
                Notification::send(
                    $advertiser,
                    new AuctionEndedForAdvertiserNotification($auction)
                );

                if ($advertiser->fcm_token) {
                    $message = $auction->winner 
                        ? "انتهى مزادك: {$auction->title} بفائز"
                        : "انتهى مزادك: {$auction->title} بدون فائز";

                    SendFcmNotification::dispatch(
                        $advertiser->fcm_token,
                        '✅ المزاد انتهى',
                        $message,
                        [
                            'type' => 'auction_ended',
                            'auction_id' => (string) $auction->id,
                        ]
                    );
                }
            }

            Log::info('NotifyAuctionWinnerJob completed', [
                'auction_id' => $this->auctionId
            ]);
        } catch (\Exception $e) {
            Log::error('NotifyAuctionWinnerJob failed', [
                'auction_id' => $this->auctionId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
