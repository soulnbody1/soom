<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicAuctionResource extends JsonResource
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
            'ends_at' => $this->ends_at?->toIso8601String(),
            'extension' => [
                'count' => $this->extension_count,
                'last_extended_at' => $this->last_extended_at?->toIso8601String(),
            ],
            'media' => AuctionMediaResource::collection($this->whenLoaded('media')),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'country' => $this->whenLoaded('country', fn () => [
                'id' => $this->country->id,
                'name' => $this->country->name,
            ]),
            'state' => $this->whenLoaded('state', fn () => $this->state ? [
                'id' => $this->state->id,
                'name' => $this->state->name,
            ] : null),
            'city' => $this->whenLoaded('city', fn () => $this->city ? [
                'id' => $this->city->id,
                'name' => $this->city->name,
            ] : null),
            'seller' => $this->whenLoaded('seller', fn () => [
                'name' => $this->seller->name,
            ]),
            'metrics' => $this->whenLoaded('metric', fn () => [
                'views_count' => $this->metric?->views_count ?? 0,
                'bids_count' => $this->metric?->bids_count ?? 0,
                'participants_count' => $this->metric?->participants_count ?? 0,
            ]),
        ];
    }
}
