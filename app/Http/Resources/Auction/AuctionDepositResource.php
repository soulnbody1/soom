<?php

declare(strict_types=1);

namespace App\Http\Resources\Auction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuctionDepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->public_id),
            'type' => $this->type,
            'status' => $this->status->value,
            'required_amount' => MoneyResource::make($this->required_amount_minor, $this->currency_code),
            'held_amount' => MoneyResource::make($this->held_amount_minor, $this->currency_code),
            'applied_amount' => MoneyResource::make($this->applied_amount_minor, $this->currency_code),
            'refunded_amount' => MoneyResource::make($this->refunded_amount_minor, $this->currency_code),
        ];
    }
}
