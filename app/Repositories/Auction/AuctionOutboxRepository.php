<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\OutboxStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class AuctionOutboxRepository
{
    /**
     * Store a new outbox message.
     * Used by AuctionAudit::outbox().
     */
    public function store(string $eventType, Auction $auction, array $payload): OutboxMessage
    {
        return OutboxMessage::create([
            'event_id' => (string) Str::ulid(),
            'topic' => 'auction.events',
            'event_type' => $eventType,
            'aggregate_type' => Auction::class,
            'aggregate_id' => $auction->id,
            'payload' => $payload,
            'status' => OutboxStatus::Pending,
            'available_at' => Carbon::now(),
        ]);
    }

    /**
     * Lease (claim) the next pending outbox message for processing.
     * Applies lockForUpdate to prevent concurrent processing.
     * Used by DispatchOutboxMessagesAction.
     */
    public function leaseNextPending(string $worker): ?OutboxMessage
    {
        $message = OutboxMessage::where('status', OutboxStatus::Pending->value)
            ->where('available_at', '<=', Carbon::now())
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', Carbon::now()->subMinutes(5));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $message) {
            return null;
        }

        $message->forceFill([
            'event_id' => $message->event_id ?: $message->public_id,
            'locked_at' => Carbon::now(),
            'locked_by' => $worker,
            'attempts' => $message->attempts + 1,
        ])->save();

        return $message->refresh();
    }

    /**
     * Mark message as published (dispatched successfully).
     */
    public function markAsPublished(OutboxMessage $message): void
    {
        $message->forceFill([
            'status' => OutboxStatus::Published,
            'processed_at' => Carbon::now(),
            'published_at' => Carbon::now(),
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => null,
        ])->save();
    }

    /**
     * Mark message as failed.
     */
    public function markAsFailed(OutboxMessage $message, string $error): void
    {
        $message->forceFill([
            'status' => OutboxStatus::Failed,
            'failed_at' => Carbon::now(),
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => $error,
        ])->save();
    }
}
