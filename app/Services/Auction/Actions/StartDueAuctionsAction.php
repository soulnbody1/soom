<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Services\Auction\Support\AuctionStateMachine;
use Illuminate\Support\Carbon;

final class StartDueAuctionsAction
{
    public function __construct(private readonly AuctionStateMachine $stateMachine) {}

    public function execute(int $limit = 100): int
    {
        $count = 0;

        Auction::query()
            ->where('status', AuctionStatus::Scheduled->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', Carbon::now())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get()
            ->each(function (Auction $auction) use (&$count): void {
                $this->stateMachine->transition($auction, AuctionStatus::Live, null, 'system', 'scheduled start time reached');
                $count++;
            });

        return $count;
    }
}
