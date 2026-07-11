<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionStateMachine;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use Illuminate\Support\Carbon;

final class CompleteHandoverAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine
    ) {}

    public function execute(Auction $auction, int $actorId): Auction
    {
        return $this->transaction->run(function () use ($auction, $actorId): Auction {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $settlement = $auction->settlement()->lockForUpdate()->firstOrFail();

            if ($settlement->status !== SettlementStatus::Paid && $settlement->status !== SettlementStatus::HandoverPending) {
                throw new AuctionException('Settlement must be paid before handover completion.');
            }

            $settlement->forceFill([
                'status' => SettlementStatus::Completed,
                'handover_completed_at' => Carbon::now(),
                'completed_at' => Carbon::now(),
            ])->save();

            return $this->stateMachine->transition($auction, AuctionStatus::Completed, $actorId, 'user', 'handover completed');
        });
    }
}
