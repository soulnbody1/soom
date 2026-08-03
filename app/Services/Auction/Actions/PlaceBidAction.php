<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\Money;
use App\DTO\Auction\BidEligibilityContextDTO;
use App\DTO\Auction\CreateBidRecordDTO;
use App\Models\Auction\AuctionBid;
use App\Repositories\Auction\AuctionBidRepository;
use App\Repositories\Auction\AuctionDepositRepository;
use App\Repositories\Auction\AuctionParticipantRepository;
use App\Repositories\Auction\AuctionRepository;
use App\Repositories\Auction\AuctionTermsRepository;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionConfigurationSnapshotReader;
use App\Services\Auction\Support\AuctionMetricsRecorder;
use App\Services\Auction\Support\AuctionTransaction;
use App\Services\Auction\Support\BidEligibilityLadder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

final class PlaceBidAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionMetricsRecorder $metrics,
        private readonly AuctionAudit $audit,
        private readonly AuctionRepository $auctions,
        private readonly AuctionBidRepository $bids,
        private readonly AuctionParticipantRepository $participants,
        private readonly AuctionDepositRepository $deposits,
        private readonly AuctionTermsRepository $terms,
        private readonly AuctionConfigurationSnapshotReader $snapshotReader,
        private readonly BidEligibilityLadder $ladder,
    ) {}

    public function execute(
        \App\Models\Auction\Auction $auction,
        int $bidderId,
        string $amount,
        string $currency,
        string $idempotencyKey,
        ?string $clientRequestId = null
    ): AuctionBid {
        $bid = $this->transaction->run(function () use ($auction, $bidderId, $amount, $currency, $idempotencyKey, $clientRequestId): AuctionBid {
            $auction = $this->auctions->lockForBidding($auction->id);
            $snapshot = $this->snapshotReader->forAuction($auction);

            $existing = $this->bids->findByIdempotencyKey($auction->id, $bidderId, $idempotencyKey);
            if ($existing) {
                return $existing->load(['auction.currentLeadingBid', 'bidder']);
            }

            $now = Carbon::now();

            $auctionGate = $this->ladder->evaluateAuctionGate(new BidEligibilityContextDTO(
                viewerId: $bidderId,
                sellerId: (int) $auction->seller_id,
                status: $auction->status,
                startsAt: $auction->starts_at,
                endsAt: $auction->ends_at,
                now: $now,
                configurationAvailable: true,
            ));

            if ($auctionGate->blocksBidding()) {
                throw AuctionException::bidRejected($auctionGate->errorKey());
            }

            $participant = $this->participants->lockParticipant($auction->id, $bidderId);
            $deposit = $participant ? $this->deposits->lockBidderDeposit($participant->id) : null;

            $participantGate = $this->ladder->evaluateParticipantGate(new BidEligibilityContextDTO(
                viewerId: $bidderId,
                sellerId: (int) $auction->seller_id,
                status: $auction->status,
                startsAt: $auction->starts_at,
                endsAt: $auction->ends_at,
                now: $now,
                configurationAvailable: true,
                participantStatus: $participant?->status,
                hasAcceptedTerms: $snapshot->terms_version_id
                    && $this->terms->hasAcceptedTerms($auction->id, $bidderId, (int) $snapshot->terms_version_id),
                requiredTermsVersionId: $snapshot->terms_version_id === null ? null : (int) $snapshot->terms_version_id,
                depositStatus: $deposit?->status,
                depositHeldMinor: (int) ($deposit->held_amount_minor ?? 0),
                depositRequiredMinor: (int) $snapshot->bidder_deposit_required_minor,
            ));

            if ($participantGate->blocksBidding()) {
                throw AuctionException::bidRejected($participantGate->errorKey());
            }

            $bidMoney = Money::fromDecimalString($amount, strtoupper($currency));
            if ($bidMoney->currency !== $snapshot->currency_code) {
                throw AuctionException::bidRejected('bid_currency_mismatch');
            }

            $previousLeaderId = $auction->currentLeadingBid?->bidder_id;
            $currentAmount = $auction->currentLeadingBid?->amount_minor ?? 0;
            $minimum = $currentAmount === 0
                ? $auction->starting_amount_minor
                : $currentAmount + (int) $snapshot->minimum_bid_increment_minor;

            if ($bidMoney->minor < $minimum) {
                throw AuctionException::bidRejected('bid_below_minimum');
            }

            $sequence = $this->bids->nextSequenceNumber($auction->id);

            try {
                $bid = $this->bids->createAcceptedBid(new CreateBidRecordDTO(
                    auctionId: $auction->id,
                    participantId: $participant->id,
                    bidderId: $bidderId,
                    amountMinor: $bidMoney->minor,
                    currencyCode: $bidMoney->currency,
                    sequenceNumber: $sequence,
                    previousBidId: $auction->current_leading_bid_id,
                    idempotencyKey: $idempotencyKey,
                    clientRequestId: $clientRequestId,
                    serverReceivedAt: $now,
                    acceptedAt: $now,
                ));
            } catch (QueryException $e) {
                if ($e->errorInfo[0] === '23000' && ($e->errorInfo[1] ?? 0) == 1062) {
                    $bid = $this->bids->findByIdempotencyKey($auction->id, $bidderId, $idempotencyKey);
                    if ($bid) {
                        return $bid->load(['auction.currentLeadingBid', 'bidder']);
                    }
                }
                throw $e;
            }

            $auction->forceFill(['current_leading_bid_id' => $bid->id]);

            $wasExtended = false;
            $secondsRemaining = $now->diffInSeconds($auction->ends_at, false);
            if (
                $secondsRemaining > 0
                && $snapshot->auto_extend_enabled
                && $secondsRemaining <= (int) $snapshot->auto_extend_window_seconds
                && $auction->extension_count < (int) $snapshot->maximum_extensions
            ) {
                $auction->forceFill([
                    'ends_at' => $auction->ends_at->addSeconds((int) $snapshot->auto_extend_duration_seconds),
                    'extension_count' => $auction->extension_count + 1,
                    'last_extended_at' => $now,
                ]);
                $wasExtended = true;
            }

            $this->auctions->save($auction);
            $this->audit->log('auction.bid_accepted', $auction, $bidderId, 'user', [
                'bid_public_id' => $bid->public_id,
                'amount_minor' => $bid->amount_minor,
                'sequence_number' => $bid->sequence_number,
            ]);
            $this->audit->outbox('auction.bid_accepted', $auction, [
                'auction_public_id' => $auction->public_id,
                'bid_public_id' => $bid->public_id,
                'bidder_id' => $bidderId,
                'amount_minor' => $bid->amount_minor,
                'currency_code' => $bid->currency_code,
                'sequence_number' => $bid->sequence_number,
                'previous_leader_id' => $previousLeaderId,
                'extended' => $wasExtended,
                'ends_at' => $auction->ends_at?->toIso8601String(),
            ]);

            return $bid->load(['auction.currentLeadingBid', 'bidder']);
        });

        $this->metrics->refreshBidMetrics($bid->auction_id);

        return $bid;
    }
}
