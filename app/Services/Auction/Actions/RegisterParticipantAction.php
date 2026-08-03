<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotIncompleteException;
use App\Domain\Auction\Exceptions\AuctionConfigurationSnapshotMissingException;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionParticipant;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionMetricsRecorder;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\ParticipantQualifier;
use Illuminate\Support\Carbon;

final class RegisterParticipantAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionMetricsRecorder $metrics,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
        private readonly ParticipantQualifier $qualifier,
    ) {}

    public function execute(Auction $auction, int $userId): AuctionParticipant
    {
        return $this->transaction->run(function () use ($auction, $userId): AuctionParticipant {
            $auction = $this->auctions->lockForStateChange($auction->id);

            if ($auction->seller_id === $userId) {
                throw AuctionException::domain('seller_cannot_register');
            }

            if (! in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
                throw AuctionException::domain('registration_closed');
            }

            $participant = $this->participants->firstOrCreateParticipant(
                $auction->id,
                $userId,
                [
                    'status' => AuctionParticipantStatus::Registered,
                    'registered_at' => Carbon::now(),
                ]
            );

            try {
                $snapshot = $this->snapshotReader->forAuction($auction);
                $this->qualifier->qualifyWhenNoDepositRequired($auction, $participant, $snapshot);
            } catch (AuctionConfigurationSnapshotMissingException|AuctionConfigurationSnapshotIncompleteException) {
            }

            $this->metrics->refreshParticipants($auction->id);
            $this->audit->log('auction.participant_registered', $auction, $userId, 'user', [
                'participant_public_id' => $participant->public_id,
            ]);

            if ($participant->wasRecentlyCreated) {
                $this->audit->outbox('auction.participant_registered', $auction, [
                    'auction_public_id' => $auction->public_id,
                    'participant_public_id' => $participant->public_id,
                    'user_id' => $userId,
                ]);
            }

            return $participant->refresh();
        });
    }
}
