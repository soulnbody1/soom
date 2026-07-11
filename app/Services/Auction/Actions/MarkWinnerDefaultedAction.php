<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\SettlementStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionWinnerReassignment;
use App\Services\Auction\Support\AuctionStateMachine;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Support\Carbon;

final class MarkWinnerDefaultedAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionStateMachine $stateMachine
    ) {}

    public function execute(Auction $auction, int $adminId, string $reason, bool $reassignToNext = false): Auction
    {
        if (trim($reason) === '') {
            throw new AuctionException(__('auction.errors.default_reason_required'));
        }

        return $this->transaction->run(function () use ($auction, $adminId, $reason, $reassignToNext): Auction {
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

            if ($reassignToNext) {
                $nextBid = AuctionBid::where('auction_id', $auction->id)
                    ->where('id', '!=', $settlement->winning_bid_id)
                    ->orderByDesc('amount_minor')
                    ->orderBy('sequence_number')
                    ->lockForUpdate()
                    ->first();

                if ($nextBid) {
                    AuctionWinnerReassignment::create([
                        'auction_id' => $auction->id,
                        'from_bid_id' => $settlement->winning_bid_id,
                        'to_bid_id' => $nextBid->id,
                        'from_user_id' => $settlement->winner_id,
                        'to_user_id' => $nextBid->bidder_id,
                        'created_by' => $adminId,
                        'reason' => $reason,
                        'metadata' => [
                            'previous_amount_minor' => $settlement->winning_amount_minor,
                            'new_amount_minor' => $nextBid->amount_minor,
                        ],
                        'created_at' => Carbon::now(),
                    ]);

                    $settlement->forceFill([
                        'winning_bid_id' => $nextBid->id,
                        'winner_id' => $nextBid->bidder_id,
                        'status' => SettlementStatus::PaymentPending,
                        'winning_amount_minor' => $nextBid->amount_minor,
                        'amount_due_minor' => max(0, $nextBid->amount_minor - $settlement->deposit_applied_minor),
                        'amount_paid_minor' => 0,
                        'paid_at' => null,
                        'handover_due_at' => null,
                    ])->save();

                    $auction->forceFill(['winning_bid_id' => $nextBid->id])->save();

                    return $this->stateMachine->transition($auction, AuctionStatus::PaymentPending, $adminId, 'admin', $reason);
                }
            }

            return $this->stateMachine->transition($auction, AuctionStatus::Defaulted, $adminId, 'admin', $reason);
        });
    }
}
