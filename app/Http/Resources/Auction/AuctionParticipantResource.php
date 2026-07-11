<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuctionParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'status' => $this->status->value,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'qualified_at' => $this->qualified_at?->toIso8601String(),
        ];
    }
}
