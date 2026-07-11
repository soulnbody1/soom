<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Actions\FinalizeAuctionAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class FinalizeExpiredAuctionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(FinalizeAuctionAction $finalize, AuctionRepository $auctions): void
    {
        $auctions->findExpiredLiveAuctions()
            ->each(fn ($auction) => $finalize->execute($auction));
    }
}
