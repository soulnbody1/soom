<?php

declare(strict_types=1);

namespace App\Console\Commands\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Market\MarketCommandRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class NormalizeLegacyDisputedAuctionsCommand extends Command
{
    protected $signature = 'auction:normalize-legacy-disputes {--market= : Required ISO market code} {--dry-run}';

    protected $description = 'Move auctions still parked in the legacy disputed status back to handover_pending.';

    public function handle(AuctionStateMachine $stateMachine, AuctionTransaction $transaction, MarketCommandRunner $markets): int
    {
        $code = trim((string) $this->option('market'));
        if ($code === '') {
            $this->error('The --market option is required.');

            return self::FAILURE;
        }

        try {
            return $markets->in($code, fn (): int => $this->handleMarket($stateMachine, $transaction));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function handleMarket(AuctionStateMachine $stateMachine, AuctionTransaction $transaction): int
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
