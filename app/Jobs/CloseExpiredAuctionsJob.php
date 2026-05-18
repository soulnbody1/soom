<?php

namespace App\Jobs;

use App\Services\AuctionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CloseExpiredAuctionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(AuctionService $auctionService): void
    {
        Log::info('Starting CloseExpiredAuctionsJob');

        try {
            $count = $auctionService->processExpiredAuctions();
            
            Log::info('CloseExpiredAuctionsJob completed', [
                'auctions_processed' => $count
            ]);
        } catch (\Exception $e) {
            Log::error('CloseExpiredAuctionsJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}
