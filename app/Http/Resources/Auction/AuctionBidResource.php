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
            'auction' => $this->whenLoaded('auction', fn () => $this->auctionSummary($user?->id)),
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

    private function auctionSummary(?int $viewerId): array
    {
        $auction = $this->auction;
        $primary = $auction->relationLoaded('media')
            ? ($auction->media->firstWhere('is_primary', true) ?? $auction->media->first())
            : null;
        $currentAmountMinor = $auction->relationLoaded('currentLeadingBid') && $auction->currentLeadingBid
            ? (int) $auction->currentLeadingBid->amount_minor
            : (int) $auction->starting_amount_minor;

        return [
            'id' => $auction->public_id,
            'title' => $auction->title,
            'status' => $auction->status->value,
            'status_label' => __('auction.statuses.'.$auction->status->value),
            'primary_image' => $primary ? (new AuctionMediaResource($primary))->resolve() : null,
            'current_amount' => MoneyResource::make($currentAmountMinor, (string) $auction->currency_code),
            'starts_at' => $auction->starts_at?->toIso8601String(),
            'ends_at' => $auction->ends_at?->toIso8601String(),
            'is_winner' => $viewerId !== null
                && $auction->relationLoaded('settlement')
                && (int) ($auction->settlement?->winner_id ?? 0) === $viewerId,
        ];
    }
}
