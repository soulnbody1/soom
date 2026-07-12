<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\DTO\Auction\Contracts\PersistenceDTO;
use Carbon\CarbonInterface;

final readonly class CreateBidRecordDTO extends BaseAuctionDTO implements PersistenceDTO
{
    public function __construct(
        public int $auctionId,
        public int $participantId,
        public int $bidderId,
        public int $amountMinor,
        public string $currencyCode,
        public int $sequenceNumber,
        public ?int $previousBidId,
        public string $idempotencyKey,
        public ?string $clientRequestId,
        public CarbonInterface $serverReceivedAt,
        public CarbonInterface $acceptedAt,
    ) {}

    public function toPersistenceArray(): array
    {
        return [
            'auction_id' => $this->auctionId,
            'participant_id' => $this->participantId,
            'bidder_id' => $this->bidderId,
            'amount_minor' => $this->amountMinor,
            'currency_code' => $this->currencyCode,
            'sequence_number' => $this->sequenceNumber,
            'previous_bid_id' => $this->previousBidId,
            'idempotency_key' => $this->idempotencyKey,
            'client_request_id' => $this->clientRequestId,
            'server_received_at' => $this->serverReceivedAt,
            'accepted_at' => $this->acceptedAt,
        ];
    }

    public function toArray(): array
    {
        return $this->toPersistenceArray();
    }
}
