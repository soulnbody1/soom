<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionStateMachine;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use Illuminate\Support\Carbon;

final class MarkWinnerDefaultedAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine
    ) {}

    public function execute(Auction $auction, int $adminId, string $reason): Auction
    {
        if (trim($reason) === '') {
            throw new AuctionException('Default reason is required.');
        }

        return $this->transaction->run(function () use ($auction, $adminId, $reason): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $settlement = $auction->settlement()->lockForUpdate()->firstOrFail();

            $settlement->forceFill([
                'status' => SettlementStatus::Defaulted,
            ])->save();

            $winnerDeposit = $auction->deposits()
                ->where('user_id', $settlement->winner_id)
                ->where('type', 'bidder')
                ->lockForUpdate()
                ->first();

            if ($winnerDeposit && $winnerDeposit->held_amount_minor > 0) {
                $winnerDeposit->forceFill([
                    'status' => AuctionDepositStatus::Forfeited,
                    'forfeited_amount_minor' => $winnerDeposit->held_amount_minor,
                    'held_amount_minor' => 0,
                    'released_at' => Carbon::now(),
                ])->save();
            }

            return $this->stateMachine->transition($auction, AuctionStatus::Defaulted, $adminId, 'admin', $reason);
        });
    }
}
