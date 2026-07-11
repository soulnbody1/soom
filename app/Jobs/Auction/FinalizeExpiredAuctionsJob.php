<?php

declare(strict_types=1);

namespace App\Jobs\Auction;

use App\Application\Auction\Actions\FinalizeAuctionAction;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

final class FinalizeExpiredAuctionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(FinalizeAuctionAction $finalize): void
    {
        Auction::where('status', AuctionStatus::Live->value)
            ->where('ends_at', '<=', Carbon::now())
            ->orderBy('ends_at')
            ->limit(100)
            ->get()
            ->each(fn (Auction $auction) => $finalize->execute($auction));
    }
}
