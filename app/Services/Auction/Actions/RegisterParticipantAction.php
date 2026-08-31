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
use App\Services\Auction\Support\AuctionTermsAcceptanceRecorder;
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
        private readonly AuctionTermsAcceptanceRecorder $acceptances,
        private readonly ParticipantQualifier $qualifier,
    ) {}

    public function execute(
        Auction $auction,
        int $userId,
        string $termsVersionPublicId,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): AuctionParticipant {
        return $this->transaction->run(function () use ($auction, $userId, $termsVersionPublicId, $ipAddress, $userAgent): AuctionParticipant {
            $auction = $this->auctions->lockForStateChange($auction->id);

            if ($auction->seller_id === $userId) {
                throw AuctionException::domain('seller_cannot_register');
            }

            $existing = $this->participants->lockParticipant($auction->id, $userId);

            if ($existing && $existing->status === AuctionParticipantStatus::Blocked) {
                throw AuctionException::domain('blocked_participant', [], 403);
            }

            if (! $existing && ! in_array($auction->status, [AuctionStatus::Scheduled, AuctionStatus::Live], true)) {
                throw AuctionException::domain('registration_closed');
            }

            try {
                $snapshot = $this->snapshotReader->forAuction($auction);
            } catch (AuctionConfigurationSnapshotMissingException|AuctionConfigurationSnapshotIncompleteException) {
                $snapshot = null;
            }

            $termsVersionId = $this->acceptances->resolveVersionId(
                $auction,
                $termsVersionPublicId,
                $snapshot?->terms_version_id === null ? null : (int) $snapshot->terms_version_id
            );

            $participant = $existing ?? $this->participants->firstOrCreateParticipant(
                $auction->id,
                $userId,
                [
                    'status' => AuctionParticipantStatus::Registered,
                    'registered_at' => Carbon::now(),
                ]
            );

            $acceptance = $this->acceptances->record(
                $auction,
                $userId,
                $termsVersionId,
                (int) $participant->id,
                $ipAddress,
                $userAgent
            );

            if ($existing === null) {
                $this->metrics->refreshParticipants($auction->id);
                $this->audit->log('auction.participant_registered', $auction, $userId, 'user', [
                    'participant_public_id' => $participant->public_id,
                    'terms_version_id' => $termsVersionId,
                ]);
                $this->audit->outbox('auction.participant_registered', $auction, [
                    'auction_public_id' => $auction->public_id,
                    'participant_public_id' => $participant->public_id,
                    'user_id' => $userId,
                ]);
            }

            if ($acceptance->wasRecentlyCreated) {
                $this->audit->log('auction.terms_accepted', $auction, $userId, 'user', [
                    'terms_version_id' => $termsVersionId,
                ]);
            }

            if ($snapshot) {
                $this->qualifier->qualifyWhenNoDepositRequired($auction, $participant, $snapshot);
            }

            return $participant->refresh();
        });
    }
}
