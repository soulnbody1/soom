<?php

declare(strict_types=1);

namespace App\Services\Auction\Actions;

use App\Domain\Auction\Enums\AuctionDepositStatus;
use App\Domain\Auction\Enums\AuctionParticipantStatus;
use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Domain\Auction\ValueObjects\Money;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionBid;
use App\Models\Auction\AuctionParticipant;
use App\Models\Auction\AuctionTermsAcceptance;
use App\Services\Auction\Support\AuctionAudit;
use App\Services\Auction\Support\AuctionMetricsRecorder;
use App\Services\Auction\Support\AuctionTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

final class PlaceBidAction
{
    public function __construct(
        private readonly AuctionTransaction $transaction,
        private readonly AuctionMetricsRecorder $metrics,
        private readonly AuctionAudit $audit
    ) {}

    public function execute(
        Auction $auction,
        int $bidderId,
        string $amount,
        string $currency,
        string $idempotencyKey,
        ?string $clientRequestId = null
    ): AuctionBid {
        return $this->transaction->run(function () use ($auction, $bidderId, $amount, $currency, $idempotencyKey, $clientRequestId): AuctionBid {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            $existing = AuctionBid::where('auction_id', $auction->id)
                ->where('bidder_id', $bidderId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

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

            $participant = AuctionParticipant::where('auction_id', $auction->id)
                ->where('user_id', $bidderId)
                ->lockForUpdate()
                ->first();

            if (! $participant || $participant->status !== AuctionParticipantStatus::Qualified) {
                throw AuctionException::bidRejected(__('auction.errors.bidder_not_qualified'));
            }

            $acceptedTerms = AuctionTermsAcceptance::where('auction_id', $auction->id)
                ->where('user_id', $bidderId)
                ->where('terms_version_id', $auction->terms_version_id)
                ->exists();

            if (! $acceptedTerms) {
                throw AuctionException::bidRejected(__('auction.errors.terms_required_before_bidding'));
            }

            $deposit = $participant->bidderDeposit()->lockForUpdate()->first();
            if (! $deposit || $deposit->status !== AuctionDepositStatus::Held || $deposit->held_amount_minor < $auction->bidder_deposit_amount_minor) {
                throw AuctionException::bidRejected(__('auction.errors.bidder_deposit_required'));
            }

            $bidMoney = Money::fromDecimalString($amount, strtoupper($currency));
            if ($bidMoney->currency !== $auction->currency_code) {
                throw AuctionException::bidRejected(__('auction.errors.bid_currency_mismatch'));
            }

            $currentAmount = $auction->currentLeadingBid?->amount_minor ?? 0;
            $minimum = $currentAmount === 0
                ? $auction->starting_amount_minor
                : $currentAmount + $auction->minimum_bid_increment_minor;

            if ($bidMoney->minor < $minimum) {
                throw AuctionException::bidRejected(__('auction.errors.bid_below_minimum'));
            }

            $sequence = ((int) AuctionBid::where('auction_id', $auction->id)->max('sequence_number')) + 1;

            try {
                $bid = AuctionBid::create([
                    'auction_id' => $auction->id,
                    'participant_id' => $participant->id,
                    'bidder_id' => $bidderId,
                    'amount_minor' => $bidMoney->minor,
                    'currency_code' => $bidMoney->currency,
                    'sequence_number' => $sequence,
                    'previous_bid_id' => $auction->current_leading_bid_id,
                    'idempotency_key' => $idempotencyKey,
                    'client_request_id' => $clientRequestId,
                    'server_received_at' => $now,
                    'accepted_at' => $now,
                ]);
            } catch (QueryException) {
                $bid = AuctionBid::where('auction_id', $auction->id)
                    ->where('bidder_id', $bidderId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();

                return $bid->load(['auction.currentLeadingBid', 'bidder']);
            }

            $auction->forceFill(['current_leading_bid_id' => $bid->id]);

            $secondsRemaining = $now->diffInSeconds($auction->ends_at, false);
            if (
                $secondsRemaining > 0
                && $secondsRemaining <= $auction->extension_window_seconds
                && $auction->extension_count < $auction->maximum_extension_count
            ) {
                $auction->forceFill([
                    'ends_at' => $auction->ends_at->addSeconds($auction->extension_duration_seconds),
                    'extension_count' => $auction->extension_count + 1,
                    'last_extended_at' => $now,
                ]);
            }

            $auction->save();
            $this->metrics->refreshBidMetrics($auction->id);
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
            ]);

            return $bid->load(['auction.currentLeadingBid', 'bidder']);
        });
    }
}
