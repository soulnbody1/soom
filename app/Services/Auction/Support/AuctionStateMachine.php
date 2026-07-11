<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Exceptions\AuctionException;
use App\Models\Auction\Auction;
use Illuminate\Support\Carbon;

final class AuctionStateMachine
{
    private const ALLOWED = [
        AuctionStatus::Draft->value => [
            AuctionStatus::PendingReview,
            AuctionStatus::Cancelled,
        ],
        AuctionStatus::PendingReview->value => [
            AuctionStatus::Rejected,
            AuctionStatus::AwaitingSellerDeposit,
            AuctionStatus::Scheduled,
            AuctionStatus::Cancelled,
        ],
        AuctionStatus::Rejected->value => [
            AuctionStatus::Draft,
            AuctionStatus::Cancelled,
        ],
        AuctionStatus::AwaitingSellerDeposit->value => [
            AuctionStatus::Scheduled,
            AuctionStatus::Cancelled,
        ],
        AuctionStatus::Scheduled->value => [
            AuctionStatus::Live,
            AuctionStatus::Cancelled,
        ],
        AuctionStatus::Live->value => [
            AuctionStatus::Ended,
            AuctionStatus::Cancelled,
            AuctionStatus::Disputed,
        ],
        AuctionStatus::Ended->value => [
            AuctionStatus::Unsold,
            AuctionStatus::SettlementPending,
            AuctionStatus::Cancelled,
            AuctionStatus::Disputed,
        ],
        AuctionStatus::SettlementPending->value => [
            AuctionStatus::PaymentPending,
            AuctionStatus::HandoverPending,
            AuctionStatus::Defaulted,
            AuctionStatus::Cancelled,
            AuctionStatus::Disputed,
        ],
        AuctionStatus::PaymentPending->value => [
            AuctionStatus::HandoverPending,
            AuctionStatus::Defaulted,
            AuctionStatus::Cancelled,
            AuctionStatus::Disputed,
        ],
        AuctionStatus::HandoverPending->value => [
            AuctionStatus::Completed,
            AuctionStatus::Cancelled,
            AuctionStatus::Disputed,
        ],
        AuctionStatus::Defaulted->value => [
            AuctionStatus::PaymentPending,
            AuctionStatus::HandoverPending,
            AuctionStatus::Unsold,
        ],
        AuctionStatus::Disputed->value => [
            AuctionStatus::PaymentPending,
            AuctionStatus::HandoverPending,
            AuctionStatus::Completed,
            AuctionStatus::Cancelled,
        ],
    ];

    public function __construct(private readonly AuctionAudit $audit) {}

    public function transition(
        Auction $auction,
        AuctionStatus $to,
        ?int $actorId,
        string $actorType,
        ?string $reason = null,
        array $metadata = []
    ): Auction {
        $from = $auction->status instanceof AuctionStatus
            ? $auction->status
            : AuctionStatus::from((string) $auction->status);

        if (! in_array($to, self::ALLOWED[$from->value] ?? [], true) && $from !== $to) {
            throw AuctionException::invalidTransition($from->value, $to->value);
        }

        if ($from === $to) {
            return $auction;
        }

        $now = Carbon::now();
        $attributes = ['status' => $to];

        match ($to) {
            AuctionStatus::Scheduled => $attributes['published_at'] = $auction->published_at ?? $now,
            AuctionStatus::Live => $attributes['started_at'] = $auction->started_at ?? $now,
            AuctionStatus::Ended => $attributes['ended_at'] = $auction->ended_at ?? $now,
            AuctionStatus::Completed => $attributes['completed_at'] = $auction->completed_at ?? $now,
            AuctionStatus::Cancelled => $attributes['cancelled_at'] = $auction->cancelled_at ?? $now,
            AuctionStatus::SettlementPending,
            AuctionStatus::PaymentPending,
            AuctionStatus::Unsold => $attributes['finalized_at'] = $auction->finalized_at ?? $now,
            default => null,
        };

        $auction->forceFill($attributes)->save();
        $this->audit->statusChanged($auction, $from, $to, $actorId, $actorType, $reason, $metadata);
        $this->audit->outbox('auction.status_changed', $auction, [
            'auction_public_id' => $auction->public_id,
            'from' => $from->value,
            'to' => $to->value,
            'reason' => $reason,
        ]);

        return $auction->refresh();
    }
}
