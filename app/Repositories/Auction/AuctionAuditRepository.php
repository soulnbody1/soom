<?php

declare(strict_types=1);

namespace App\Repositories\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionStatusHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AuctionAuditRepository
{
    /**
     * Paginate activity logs of an auction for the admin dashboard.
     * Used by AuctionAuditController::activity.
     */
    public function paginateActivityForAuction(Auction $auction, int $perPage): LengthAwarePaginator
    {
        return AuctionActivityLog::with('user:id,name')
            ->where('auction_id', $auction->id)
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Full status-transition history of an auction, oldest first.
     * Used by AuctionAuditController::statusHistory.
     */
    public function statusHistoryForAuction(Auction $auction): Collection
    {
        return AuctionStatusHistory::with('changedBy:id,name')
            ->where('auction_id', $auction->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

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
