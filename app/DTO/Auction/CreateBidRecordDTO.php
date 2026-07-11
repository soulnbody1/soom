<?php

declare(strict_types=1);

namespace App\DTO\Auction;

use App\DTO\Auction\Contracts\PersistenceDTO;
use Carbon\CarbonInterface;

final readonly class CreateBidRecordDTO extends BaseAuctionDTO implements PersistenceDTO
{
    public function __construct(
        public int $auction_id,
        public int $participant_id,
        public int $bidder_id,
        public int $amount_minor,
        public string $currency_code,
        public int $sequence_number,
        public ?int $previous_bid_id,
        public string $idempotency_key,
        public ?string $client_request_id,
        public CarbonInterface $server_received_at,
        public CarbonInterface $accepted_at,
    ) {}

    public function toPersistenceArray(): array
    {
        return [
            'auction_id' => $this->auction_id,
            'participant_id' => $this->participant_id,
            'bidder_id' => $this->bidder_id,
            'amount_minor' => $this->amount_minor,
            'currency_code' => $this->currency_code,
            'sequence_number' => $this->sequence_number,
            'previous_bid_id' => $this->previous_bid_id,
            'idempotency_key' => $this->idempotency_key,
            'client_request_id' => $this->client_request_id,
            'server_received_at' => $this->server_received_at,
            'accepted_at' => $this->accepted_at,
        ];
    }

    public function toArray(): array
    {
        return $this->toPersistenceArray();
    }
}
