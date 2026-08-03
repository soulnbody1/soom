<?php

declare(strict_types=1);

namespace App\Console\Commands\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Console\Command;

final class NormalizeLegacyDisputedAuctionsCommand extends Command
{
    protected $signature = 'auction:normalize-legacy-disputes {--dry-run}';

    protected $description = 'Move auctions still parked in the legacy disputed status back to handover_pending.';

    public function handle(AuctionStateMachine $stateMachine, AuctionTransaction $transaction): int
    {
        $auctions = Auction::where('status', AuctionStatus::Disputed->value)->get();

        if ($auctions->isEmpty()) {
            $this->info('No auctions are parked in the legacy disputed status.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Public ID', 'Title'],
                $auctions->map(fn (Auction $auction) => [$auction->public_id, $auction->title])
            );
            $this->info($auctions->count().' auction(s) would move to handover_pending.');

            return self::SUCCESS;
        }

        $moved = 0;
        foreach ($auctions as $auction) {
            $transaction->run(function () use ($auction, $stateMachine, &$moved): void {
                $stateMachine->transition(
                    $auction,
                    AuctionStatus::HandoverPending,
                    null,
                    'system',
                    'legacy disputed status normalized'
                );
                $moved++;
            });
        }

        $this->info($moved.' auction(s) moved to handover_pending.');

        return self::SUCCESS;
    }
}
