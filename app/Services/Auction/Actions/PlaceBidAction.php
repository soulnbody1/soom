<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\Money;
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

            if ($auction->seller_id === $bidderId) {
                throw AuctionException::bidRejected(__('auction.errors.seller_cannot_bid'));
            }

            if ($auction->status !== AuctionStatus::Live || ! $auction->starts_at || ! $auction->ends_at) {
                throw AuctionException::bidRejected(__('auction.errors.auction_not_live'));
            }

            if ($auction->starts_at->greaterThan($now) || ! $now->lessThan($auction->ends_at)) {
                throw AuctionException::bidRejected(__('auction.errors.bidding_window_closed'));
            }

            $participant = $this->participants->lockParticipant($auction->id, $bidderId);

            if (! $participant || $participant->status !== \App\Domain\Auction\Enums\AuctionParticipantStatus::Qualified) {
                throw AuctionException::bidRejected(__('auction.errors.bidder_not_qualified'));
            }

            if (! $snapshot->terms_version_id || ! $this->terms->hasAcceptedTerms($auction->id, $bidderId, (int) $snapshot->terms_version_id)) {
                throw AuctionException::bidRejected(__('auction.errors.terms_required_before_bidding'));
            }

            $deposit = $this->deposits->lockBidderDeposit($participant->id);
            if (! $deposit || $deposit->status !== \App\Domain\Auction\Enums\AuctionDepositStatus::Held || $deposit->held_amount_minor < (int) $snapshot->bidder_deposit_required_minor) {
                throw AuctionException::bidRejected(__('auction.errors.bidder_deposit_required'));
            }

            $bidMoney = Money::fromDecimalString($amount, strtoupper($currency));
            if ($bidMoney->currency !== $snapshot->currency_code) {
                throw AuctionException::bidRejected(__('auction.errors.bid_currency_mismatch'));
            }

            $previousLeaderId = $auction->currentLeadingBid?->bidder_id;
            $currentAmount = $auction->currentLeadingBid?->amount_minor ?? 0;
            $minimum = $currentAmount === 0
                ? $auction->starting_amount_minor
                : $currentAmount + (int) $snapshot->minimum_bid_increment_minor;

            if ($bidMoney->minor < $minimum) {
                throw AuctionException::bidRejected(__('auction.errors.bid_below_minimum'));
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
