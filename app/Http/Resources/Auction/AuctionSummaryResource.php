<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use App\Domain\Auction\Enums\AuctionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuctionSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'starting_amount' => MoneyResource::make($this->starting_amount_minor, $this->currency_code),
            'current_amount' => MoneyResource::make(
                $this->relationLoaded('currentLeadingBid') ? ($this->currentLeadingBid?->amount_minor ?? $this->starting_amount_minor) : $this->starting_amount_minor,
                $this->currency_code
            ),
            'reserve_met' => $this->reserve_amount_minor === null
                ? null
                : (($this->relationLoaded('currentLeadingBid') ? ($this->currentLeadingBid?->amount_minor ?? 0) : 0) >= $this->reserve_amount_minor),
            'media' => AuctionMediaResource::collection($this->whenLoaded('media')),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'metrics' => $this->whenLoaded('metric', fn () => [
                'views_count' => $this->metric?->views_count ?? 0,
                'bids_count' => $this->metric?->bids_count ?? 0,
                'participants_count' => $this->metric?->participants_count ?? 0,
            ]),
        ];
    }
}
