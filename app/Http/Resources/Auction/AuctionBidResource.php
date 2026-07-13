<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class AuctionBidResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canViewBidder = $user && (
            $user->id === $this->bidder_id
            || Gate::forUser($user)->allows('viewAny', \App\Models\Auction\Auction::class)
        );

        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'amount' => MoneyResource::make($this->amount_minor, $this->currency_code),
            'sequence_number' => $this->sequence_number,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'bidder' => $canViewBidder
                ? $this->whenLoaded('bidder', fn () => [
                    'id' => $this->bidder->id,
                    'name' => $this->bidder->name,
                ])
                : ['anonymous' => true],
        ];
    }
}
