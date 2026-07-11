<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuctionBidResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'amount' => MoneyResource::make($this->amount_minor, $this->currency_code),
            'sequence_number' => $this->sequence_number,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'bidder' => $request->user()?->role === 'admin' || $request->user()?->id === $this->bidder_id
                ? $this->whenLoaded('bidder', fn () => [
                    'id' => $this->bidder->id,
                    'name' => $this->bidder->name,
                ])
                : ['anonymous' => true],
        ];
    }
}
