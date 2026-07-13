<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Domain\Auction\Enums\AuctionStatus;
use App\Domain\Auction\Enums\OutboxStatus;
use App\DTO\Auction\CreateOutboxMessageDTO;
use App\Models\Auction\Auction;
use App\Repositories\Auction\AuctionAuditRepository;
use App\Repositories\Auction\AuctionOutboxRepository;
use App\Services\Auction\Actions\DispatchOutboxMessagesAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class AuctionAudit
{
    public function __construct(
        private readonly AuctionAuditRepository $auditRepo,
        private readonly AuctionOutboxRepository $outboxRepo,
    ) {}

    public function statusChanged(
        Auction $auction,
        ?AuctionStatus $from,
        AuctionStatus $to,
        ?int $actorId,
        string $actorType,
        ?string $reason = null,
        array $metadata = []
    ): void {
        $this->auditRepo->recordStatusTransition(
            $auction->id,
            $from,
            $to,
            $actorId,
            $actorType,
            $reason,
            $metadata === [] ? null : $metadata
        );
    }

    public function log(
        string $eventType,
        ?Auction $auction,
        ?int $actorId,
        string $actorType = 'system',
        array $metadata = []
    ): void {
        $this->auditRepo->logActivity(
            $auction?->id,
            $actorId,
            $eventType,
            $actorType,
            $metadata === [] ? null : $metadata
        );
    }

    public function outbox(string $eventType, Auction $auction, array $payload): void
    {
        if (! DispatchOutboxMessagesAction::supports($eventType)) {
            return;
        }

        $this->outboxRepo->store(new CreateOutboxMessageDTO(
            eventId: (string) Str::ulid(),
            topic: 'auction.events',
            eventType: $eventType,
            aggregateType: Auction::class,
            aggregateId: $auction->id,
            payload: $payload,
            status: OutboxStatus::Pending,
            availableAt: Carbon::now(),
        ));
    }
}
