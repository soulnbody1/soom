<?php

declare(strict_types=1);

namespace App\Application\Auction\Actions;

use App\Application\Auction\Services\AuctionAudit;
use App\Application\Auction\Services\AuctionMetricsRecorder;
use App\Application\Auction\Services\AuctionTransaction;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionParticipant;
use Illuminate\Support\Carbon;

final class RegisterParticipantAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionMetricsRecorder $metrics,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(Auction $auction, int $userId): AuctionParticipant
    {
        return $this->transaction->run(function () use ($auction, $userId): AuctionParticipant {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            if ($auction->seller_id === $userId) {
                throw new AuctionException('Seller cannot register as bidder.');
            }

            if (! in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
                throw new AuctionException('Auction is not open for participant registration.');
            }

            $participant = AuctionParticipant::firstOrCreate(
                ['auction_id' => $auction->id, 'user_id' => $userId],
                [
                    'status' => AuctionParticipantStatus::Registered,
                    'registered_at' => Carbon::now(),
                ]
            );

            $this->metrics->refreshParticipants($auction->id);
            $this->audit->log('auction.participant_registered', $auction, $userId, 'user', [
                'participant_public_id' => $participant->public_id,
            ]);

            return $participant->refresh();
        });
    }
}
