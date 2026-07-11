<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\OutboxStatus;
use App\Models\Auction\Auction;
use App\Models\Auction\AuctionActivityLog;
use App\Models\Auction\AuctionStatusHistory;
use App\Models\Auction\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class AuctionAudit
{
    public function statusChanged(
        Auction $auction,
        ?AuctionStatus $from,
        AuctionStatus $to,
        ?int $actorId,
        string $actorType,
        ?string $reason = null,
        array $metadata = []
    ): void {
        AuctionStatusHistory::create([
            'auction_id' => $auction->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by' => $actorId,
            'actor_type' => $actorType,
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => Carbon::now(),
        ]);
    }

    public function log(
        string $eventType,
        ?Auction $auction,
        ?int $actorId,
        string $actorType = 'system',
        array $metadata = []
    ): void {
        AuctionActivityLog::create([
            'auction_id' => $auction?->id,
            'user_id' => $actorId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => Carbon::now(),
        ]);
    }

    public function outbox(string $eventType, Auction $auction, array $payload): void
    {
        OutboxMessage::create([
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
}
