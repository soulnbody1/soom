<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\OutboxStatus;
use App\DTO\Auction\CreateOutboxMessageDTO;
use App\Models\Auction\OutboxMessage;
use Illuminate\Support\Carbon;

final class AuctionOutboxRepository
{
    /**
     * Store a new outbox message.
     * Used by AuctionAudit::outbox().
     */
    public function store(CreateOutboxMessageDTO $dto): OutboxMessage
    {
        return OutboxMessage::create($dto->toPersistenceArray());
    }

    /**
     * Lease (claim) the next due outbox message for processing.
     * Applies lockForUpdate to prevent concurrent processing.
     * Used by DispatchOutboxMessagesAction.
     */
    public function leaseNextPending(string $worker): ?OutboxMessage
    {
        $now = Carbon::now();
        $lockExpiredAt = $now->copy()->subMinutes(5);

        $message = OutboxMessage::query()
            ->where(function ($query) use ($lockExpiredAt, $now): void {
                $query->where(function ($query) use ($lockExpiredAt, $now): void {
                    $query->where('status', OutboxStatus::Pending->value)
                        ->where('available_at', '<=', $now)
                        ->where(function ($query) use ($lockExpiredAt): void {
                            $query->whereNull('locked_at')
                                ->orWhere('locked_at', '<=', $lockExpiredAt);
                        });
                })->orWhere(function ($query) use ($lockExpiredAt): void {
                    $query->where('status', OutboxStatus::Processing->value)
                        ->where('locked_at', '<=', $lockExpiredAt);
                });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $message) {
            return null;
        }

        $message->forceFill([
            'event_id' => $message->event_id ?: $message->public_id,
            'status' => OutboxStatus::Processing,
            'locked_at' => Carbon::now(),
            'locked_by' => $worker,
            'attempts' => $message->attempts + 1,
        ])->save();

        return $message->refresh();
    }

    /**
     * Mark message as processed successfully.
     */
    public function markAsProcessed(OutboxMessage $message): void
    {
        $message->forceFill([
            'status' => OutboxStatus::Processed,
            'processed_at' => Carbon::now(),
            'published_at' => Carbon::now(),
            'dead_lettered_at' => null,
            'failed_at' => null,
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
        $maxAttempts = (int) config('auction.outbox.max_attempts', 3);
        $retryDelaySeconds = (int) config('auction.outbox.retry_delay_seconds', 300);

        if ($message->attempts >= $maxAttempts) {
            $message->forceFill([
                'status' => OutboxStatus::Failed,
                'failed_at' => Carbon::now(),
                'dead_lettered_at' => Carbon::now(),
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => $error,
            ])->save();

            return;
        }

        $nextRetryAt = Carbon::now()->addSeconds($retryDelaySeconds);

        $message->forceFill([
            'status' => OutboxStatus::Pending,
            'failed_at' => Carbon::now(),
            'available_at' => $nextRetryAt,
            'next_retry_at' => $nextRetryAt,
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => $error,
        ])->save();
    }
}
