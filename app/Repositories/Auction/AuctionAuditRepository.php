<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionStatusHistory;
use Illuminate\Support\Carbon;

final class AuctionAuditRepository
{
    /**
     * Record an activity log entry.
     * Used by AuctionAudit::log().
     */
    public function logActivity(
        ?int $auctionId,
        ?int $actorId,
        string $eventType,
        string $actorType = 'system',
        ?array $metadata = null
    ): AuctionActivityLog {
        return AuctionActivityLog::create([
            'auction_id' => $auctionId,
            'user_id' => $actorId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'metadata' => $metadata,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Record a status transition.
     * Used by AuctionAudit::statusChanged().
     */
    public function recordStatusTransition(
        int $auctionId,
        ?AuctionStatus $from,
        AuctionStatus $to,
        ?int $actorId,
        string $actorType,
        ?string $reason = null,
        ?array $metadata = null
    ): AuctionStatusHistory {
        return AuctionStatusHistory::create([
            'auction_id' => $auctionId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by' => $actorId,
            'actor_type' => $actorType,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => Carbon::now(),
        ]);
    }
}
