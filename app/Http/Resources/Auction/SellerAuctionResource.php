<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


final class SellerAuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => __('auction.statuses.' . $this->status->value),
            'currency_code' => $this->currency_code,
            'starting_amount' => MoneyResource::make($this->starting_amount_minor, $this->currency_code),
            'reserve_amount' => $this->reserve_amount_minor !== null
                ? MoneyResource::make($this->reserve_amount_minor, $this->currency_code)
                : null,
            'minimum_bid_increment' => MoneyResource::make($this->minimum_bid_increment_minor, $this->currency_code),
            'seller_deposit_amount' => MoneyResource::make($this->seller_deposit_amount_minor, $this->currency_code),
            'bidder_deposit_amount' => MoneyResource::make($this->bidder_deposit_amount_minor, $this->currency_code),
            'platform_fee' => [
                'type' => $this->platform_fee_type,
                'basis_points' => $this->platform_fee_basis_points,
                'fixed' => MoneyResource::make($this->platform_fee_fixed_minor, $this->currency_code),
            ],
            'current_amount' => MoneyResource::make(
                $this->relationLoaded('currentLeadingBid')
                    ? ($this->currentLeadingBid?->amount_minor ?? $this->starting_amount_minor)
                    : $this->starting_amount_minor,
                $this->currency_code
            ),
            'reserve_met' => $this->reserve_amount_minor === null
                ? null
                : (($this->relationLoaded('currentLeadingBid') ? ($this->currentLeadingBid?->amount_minor ?? 0) : 0) >= $this->reserve_amount_minor),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'original_ends_at' => $this->original_ends_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'extension' => [
                'window_seconds' => $this->extension_window_seconds,
                'duration_seconds' => $this->extension_duration_seconds,
                'maximum_count' => $this->maximum_extension_count,
                'count' => $this->extension_count,
                'last_extended_at' => $this->last_extended_at?->toIso8601String(),
            ],
            'media' => AuctionMediaResource::collection($this->whenLoaded('media')),
            'category' => $this->whenLoaded('category'),
            'country' => $this->whenLoaded('country'),
            'state' => $this->whenLoaded('state'),
            'city' => $this->whenLoaded('city'),
            'leading_bid' => new AuctionBidResource($this->whenLoaded('currentLeadingBid')),
            'winning_bid' => new AuctionBidResource($this->whenLoaded('winningBid')),
            'settlement' => $this->whenLoaded('settlement', fn () => [
                'status' => $this->settlement->status->value,
                'winning_amount' => MoneyResource::make($this->settlement->winning_amount_minor, $this->currency_code),
                'seller_net_amount' => MoneyResource::make($this->settlement->seller_net_amount_minor, $this->currency_code),
                'platform_fee' => MoneyResource::make($this->settlement->platform_fee_minor, $this->currency_code),
                'completed_at' => $this->settlement->completed_at?->toIso8601String(),
            ]),
            'metrics' => $this->whenLoaded('metric', fn () => [
                'views_count' => $this->metric?->views_count ?? 0,
                'unique_views_count' => $this->metric?->unique_views_count ?? 0,
                'participants_count' => $this->metric?->participants_count ?? 0,
                'bids_count' => $this->metric?->bids_count ?? 0,
                'unique_bidders_count' => $this->metric?->unique_bidders_count ?? 0,
            ]),
        ];
    }
}
